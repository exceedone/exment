<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\PublicFormService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Public forms made by code (ISS02942): ensure() makes and updates one public form for an owner,
 * the values every submission gets (fixed_values), the submissions a hour from one address
 * (rate_limit), and the tags to put the form in a web site (embed.js, ?embed=1).
 */
class PublicFormServiceTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const OWNER = 'claude_test:queue:1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    protected function table(): CustomTable
    {
        /** @var CustomTable $table */
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        return $table;
    }

    /**
     * @return array<int, string> the column names of the form, in order
     */
    protected function formColumns(PublicForm $form): array
    {
        $block = $form->custom_form->custom_form_blocks->first();
        return CustomFormColumn::query()->where('custom_form_block_id', $block->id)->orderBy('order')->get()->map(function ($column) {
            return strval($column->column_item->name());
        })->all();
    }

    /**
     * What the browser sends: the hidden fields of the form page, and the values typed.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected function formData(PublicForm $form, array $values): array
    {
        $page = strval($this->get($form->getUrl())->getContent());
        $data = [];
        preg_match_all('/<input[^>]*type="hidden"[^>]*>/', $page, $inputs);
        foreach ($inputs[0] as $input) {
            if (preg_match('/name="([^"]+)"/', $input, $name) && !str_starts_with($name[1], 'value[')) {
                preg_match('/value="([^"]*)"/', $input, $value);
                $data[html_entity_decode($name[1])] = html_entity_decode($value[1] ?? '');
            }
        }
        $data['value'] = $values;
        return $data;
    }

    public function testMakeAndUpdateInPlace(): void
    {
        $this->assertNull(PublicFormService::findByOwner(static::OWNER));
        $form = PublicFormService::ensure($this->table(), static::OWNER, ['text', 'email'], [
            'view_name' => 'Contact us',
            'complete_text' => 'Thank you.',
            'notify_user_column' => 'email',
            'notify_admin_users' => [LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id],
            'fixed_values' => ['odd_even' => 'web'],
        ]);
        $this->assertTrue(boolval($form->active_flg));
        $this->assertSame('Contact us', $form->public_form_view_name);
        $this->assertSame('Thank you.', $form->getOption('complete_text'));
        $this->assertSame(['odd_even' => 'web'], PublicFormService::fixedValues($form));
        $this->assertSame(['text', 'email'], $this->formColumns($form));
        $this->assertNotNull($form->notify_complete_user);
        $this->assertNotNull($form->notify_complete_admin);
        $this->assertSame($form->id, PublicFormService::findByOwner(static::OWNER)?->id);

        // the same owner: the same form (its url stays), the columns in the new order, no receipt mail
        $again = PublicFormService::ensure($this->table(), static::OWNER, ['email', 'text', 'index_text'], ['active' => false]);
        $this->assertSame($form->id, $again->id);
        $this->assertSame($form->uuid, $again->uuid);
        $this->assertSame($form->custom_form_id, $again->custom_form_id);
        $this->assertSame(['email', 'text', 'index_text'], $this->formColumns($again->fresh()));
        $this->assertFalse(boolval($again->active_flg));
        $this->assertNull($again->fresh()->notify_complete_user);
    }

    public function testWrongColumns(): void
    {
        foreach ([
            [['no_such_column'], []],
            [[], []],
            [['text'], ['fixed_values' => ['text' => 'x']]],
            [['text'], ['fixed_values' => ['no_such_column' => 'x']]],
        ] as [$columns, $opts]) {
            try {
                PublicFormService::ensure($this->table(), static::OWNER, $columns, $opts);
                $this->fail('accepted: ' . json_encode([$columns, $opts]));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('PublicFormService', $e->getMessage());
            }
        }
        $this->assertNull(PublicFormService::findByOwner(static::OWNER));
    }

    public function testASubmissionGetsTheFixedValuesAndTheLimit(): void
    {
        $form = PublicFormService::ensure($this->table(), static::OWNER, ['text'], [
            'fixed_values' => ['odd_even' => 'from the web'],
            'rate_limit' => 1,
            'recaptcha' => false,
        ]);
        RateLimiter::clear('exment_public_form:' . $form->uuid . ':127.0.0.1');
        \Auth::guard('admin')->logout();
        // the form and its columns are read again by the next request (cached for this one)
        System::clearRequestSession();
        System::clearCache();
        $model = $this->table()->getValueModel();
        $before = $model->query()->count();
        $saw = null;
        // the saving event of the table already sees the fixed value (a value sent for it is not used)
        $model::saving(function ($value) use (&$saw) {
            $saw = $value->getValue('odd_even');
        });

        $this->post($form->getUrl('create'), $this->formData($form, ['text' => 'sent from a web site', 'odd_even' => 'typed by the visitor']))->assertStatus(200);
        $this->assertSame($before + 1, $model->query()->count());
        $row = $model->query()->where('value->text', 'sent from a web site')->first();
        $this->assertNotNull($row, strval(json_encode($model->query()->orderByDesc('id')->limit(2)->get(['id', 'value']))));
        $this->assertSame('from the web', $row->getValue('odd_even'));
        $this->assertSame('from the web', $saw);

        // over the limit of the hour: not saved
        $this->post($form->getUrl('create'), $this->formData($form, ['text' => 'once more']));
        $this->assertSame($before + 1, $model->query()->count());
    }

    public function testTheTagsAndTheScript(): void
    {
        $form = PublicFormService::ensure($this->table(), static::OWNER, ['text'], ['view_name' => 'Ask "us"']);
        $tags = PublicFormService::embedTags($form);
        $this->assertSame($form->getUrl(), $tags['url']);
        $this->assertStringContainsString('src="' . e($form->getUrl() . '?embed=1') . '"', $tags['iframe']);
        $this->assertStringContainsString('title="Ask &quot;us&quot;"', $tags['iframe']);
        $this->assertSame('<script src="' . e($form->getUrl('embed.js')) . '" async></script>', $tags['script']);

        \Auth::guard('admin')->logout();
        $response = $this->get($form->getUrl('embed.js'))->assertStatus(200);
        $this->assertStringContainsString('javascript', strval($response->headers->get('Content-Type')));
        $script = strval($response->getContent());
        $this->assertStringContainsString(strval(json_encode($form->getUrl() . '?embed=1')), $script);
        $this->assertStringContainsString(strval(json_encode(strval($form->uuid))), $script);

        // in a frame: no header and the height is told to the page around it
        $page = strval($this->get($form->getUrl() . '?embed=1')->assertStatus(200)->getContent());
        $this->assertStringContainsString('exmentPublicForm', $page);
        $this->assertTrue($form->isEmbedded());
    }
}
