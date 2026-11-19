<?php

namespace Exceedone\Exment\Form\Field;

use ExmentAdminCore\Admin\Form\Field;

/**
 * Section navigation (table of contents) for a long form.
 * Lists every exmheader in the same form as a button that scrolls to it,
 * and adds a "back to top" link to each header.
 */
class SectionNav extends Field\Display
{
    protected $view = 'exment::form.field.section-nav';

    // @phpstan-ignore-next-line
    public function __construct($label = null)
    {
        $this->label = $label;
    }

    public function render()
    {
        $this->script = $this->getNavScript();

        // @phpstan-ignore-next-line
        return parent::render()->with([
            'navLabel' => exmtrans('common.section_nav'),
        ]);
    }

    protected function getNavScript(): string
    {
        $backLabel = json_encode(exmtrans('common.section_nav_back'));

        return <<<SCRIPT
(function () {
    $('.exm-section-nav').each(function () {
        var \$nav = $(this);
        var \$list = \$nav.find('.exm-section-nav-list').empty();
        var \$headers = \$nav.closest('form').find('.field-header');

        \$headers.each(function (index) {
            var \$header = $(this);
            var \$group = \$header.closest('.form-group');
            var id = 'exm-section-' + (index + 1);
            \$group.attr('id', id);

            $('<button type="button" class="btn btn-sm btn-default exm-section-nav-item"></button>')
                .text($.trim(\$header.text()))
                .on('click', function () {
                    document.getElementById(id).scrollIntoView({behavior: 'smooth', block: 'start'});
                })
                .appendTo(\$list);

            if (\$header.find('.exm-section-nav-back').length === 0) {
                $('<a href="javascript:void(0);" class="exm-section-nav-back small"></a>')
                    .text({$backLabel})
                    .on('click', function () {
                        \$nav[0].scrollIntoView({behavior: 'smooth', block: 'start'});
                    })
                    .appendTo(\$header);
            }
        });

        \$nav.toggle(\$headers.length > 1);
    });
})();
SCRIPT;
    }
}
