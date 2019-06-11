<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Show;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Revisionable\Revision;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\Permission;

/**
 * CustomValueShow
 */
trait CustomValueShow
{
    /**
     * create show form list
     */
    protected function createShowForm($id = null, $modal = false)
    {
        //Plugin::pluginPreparing($this->plugins, 'loading');
        return new Show($this->getModelNameDV()::findOrFail($id), function (Show $show) use ($id, $modal) {
            $custom_value = $this->custom_table->getValueModel($id);

            // add parent link if this form is 1:n relation
            $relation = CustomRelation::getRelationByChild($this->custom_table, RelationType::ONE_TO_MANY);
            if (isset($relation)) {
                $item = ColumnItems\ParentItem::getItem($relation->child_custom_table);

                $show->field($item->name(), $item->label())->as(function ($v) use ($item) {
                    if (is_null($this)) {
                        return '';
                    }
                    return $item->setCustomValue($this)->html();
                })->setEscape(false);
            }

            // loop for custom form blocks
            foreach ($this->custom_form->custom_form_blocks as $custom_form_block) {
                // if available is false, continue
                if (!$custom_form_block->available) {
                    continue;
                }
                ////// default block(no relation block)
                if (array_get($custom_form_block, 'form_block_type') == FormBlockType::DEFAULT) {
                    $hasMultiColumn = false;
                    foreach ($custom_form_block->custom_form_columns as $form_column) {
                        $item = $form_column->column_item;
                        if (!isset($item)) {
                            continue;
                        }
                        $show->field($item->name(), $item->label(), array_get($form_column, 'column_no'))
                            ->as(function ($v) use ($form_column, $item) {
                                if (is_null($this)) {
                                    return '';
                                }
                                return $item->setCustomValue($this)->html();
                            })->setEscape(false);
                    }

                    if ($custom_form_block->isMultipleColumn()) {
                        $show->setWidth(9, 3);
                    } elseif ($modal) {
                        $show->setWidth(9, 3);
                    }
                }
                ////// relation block
                else {
                    // if modal, dont show children
                    if ($modal) {
                        continue;
                    }
                    list($relation_name, $block_label) = $this->getRelationName($custom_form_block);
                    $target_table = $custom_form_block->target_table;
                    $show->{$relation_name}($block_label, function ($grid) use ($custom_form_block, $target_table) {
                        $custom_view = CustomView::getDefault($target_table);
                        $custom_view->setGrid($grid);
                        
                        $grid->disableFilter();
                        $grid->disableCreateButton();
                        $grid->disableExport();
                        $grid->tools(function ($tools) {
                            $tools->batch(function ($batch) {
                                $batch->disableDelete();
                            });
                        });
                        $grid->disableRowSelector();

                        $grid->actions(function ($actions) {
                            $actions->disableView();
                            $actions->disableEdit();
                            $actions->disableDelete();
                        
                            // add show link
                            $actions->append($actions->row->getUrl([
                                'tag' => true,
                                'modal' => true,
                                'icon' => 'fa-external-link',
                                'add_id' => true,
                            ]));
                        });
                    });
                }
            }

            // if user only view permission, disable delete and view
            if (!$this->custom_table->hasPermissionEditData($id)) {
                $show->panel()->tools(function ($tools) {
                    $tools->disableEdit();
                    $tools->disableDelete();
                });
            }

            // if modal, disable list and delete
            $show->panel()->tools(function ($tools) use ($modal, $custom_value, $id) {
                if (count($this->custom_table->getRelationTables()) > 0) {
                    $tools->append('<div class="btn-group pull-right" style="margin-right: 5px">
                        <a href="'. $custom_value->getRelationSearchUrl(true) . '" class="btn btn-sm btn-purple" title="'. exmtrans('search.header_relation') . '">
                            <i class="fa fa-compress"></i><span class="hidden-xs"> '. exmtrans('search.header_relation') . '</span>
                        </a>
                    </div>');
                }

                if ($modal) {
                    $tools->disableList();
                    $tools->disableDelete();
                } else {
                    $tools->append((new Tools\GridChangePageMenu('data', $this->custom_table, false))->render());

                    $listButtons = Plugin::pluginPreparingButton($this->plugins, 'form_menubutton_show');
                    $copyButtons = $this->custom_table->from_custom_copies;

                    foreach ($listButtons as $plugin) {
                        $tools->append(new Tools\PluginMenuButton($plugin, $this->custom_table, $id));
                    }
                    foreach ($copyButtons as $copyButton) {
                        $b = new Tools\CopyMenuButton($copyButton, $this->custom_table, $id);
                    
                        $tools->append($b->toHtml());
                    }
                }
            });
        });
    }

    /**
     * set option boxes.
     * contains file uploads, revisions
     */
    protected function setOptionBoxes($row, $id, $modal = false)
    {
        $custom_value = $this->getModelNameDV()::find($id);
        $documents = $this->getDocuments($id, $modal);
        $useFileUpload = $this->useFileUpload($modal);
        $useComment = $this->useComment($modal);
 
        $revisions = $this->getRevisions($id, $modal);

        if (count($documents) > 0 || $useFileUpload) {
            $form = new WidgetForm;
            $form->disableReset();
            //$form->action($custom_value->getUrl(['uri' => 'fileupload']));
            $form->disableSubmit();

            // show document list
            if (isset($id)) {
                if (count($documents) > 0) {
                    $html = [];
                    foreach ($documents as $index => $d) {
                        $html[] = "<p>" . view('exment::form.field.documentlink', [
                            'document' => $d
                        ])->render() . "</p>";
                    }
                    // loop and add as link
                    $form->html(implode("", $html))
                        ->plain()
                        ->setWidth(8, 3);
                }
            }

            // add file uploader
            if ($useFileUpload) {
                $options = [
                    'showUpload' => true,
                    'showPreview' => false,
                    'showCancel' => false,
                    'uploadUrl' => admin_urls('data', $this->custom_table->table_name, $id, 'fileupload'),
                    'uploadExtraData'=> [
                        '_token' => csrf_token()
                    ],
                ];
                $options_json = json_encode($options);

                $input_id = 'file_data';
                $form->file($input_id, trans('admin.upload'))
                ->options($options)
                ->setLabelClass(['d-none'])
                ->setWidth(12, 0);
                // // create file upload option
                //             $form->html('<input type="file" id="'.$input_id.'" />')->plain();
                $script = <<<EOT
    $(".$input_id").on('fileuploaded', function(e, params) {
        console.log('file uploaded', e, params);
        $.pjax.reload('#pjax-container');
    });

EOT;
                Admin::script($script);
            }
            $row->column(6, (new Box(exmtrans("common.attachment"), $form))->style('info'));
        }

        if (count($revisions) > 0) {
            $form = new WidgetForm;
            $form->disableReset();
            $form->disableSubmit();
            $form->attribute(['class' => 'form-horizontal form-revision']);
    
            foreach ($revisions as $index => $revision) {
                $form->html(
                    view('exment::form.field.revisionlink', [
                        'revision' => $revision,
                        'link' => admin_urls('data', $this->custom_table->table_name, $id, 'compare?revision='.$revision->suuid),
                        'index' => $index,
                    ])->render(),
                    'No.'.($revision->revision_no)
                )->setWidth(9, 2);
            }
            $row->column(6, (new Box(exmtrans('revision.update_history'), $form))->style('info'));
        }

        if ($useComment) {
            $comments = $this->getComments($id, $modal);
            $form = new WidgetForm;
            $form->disableReset();
            $form->action(admin_urls('data', $this->custom_table->table_name, $id, 'addcomment'));
            $form->setWidth(10, 2);

            if (count($comments) > 0) {
                $html = [];
                foreach ($comments as $index => $comment) {
                    $html[] = "<p>" . view('exment::form.field.commentline', [
                        'comment' => $comment,
                        'table_name' => $this->custom_table->table_name,
                        'isAbleRemove' => ($comment->created_user_id == \Exment::user()->base_user_id),
                    ])->render() . "</p>";
                }
                // loop and add as link
                $form->html(implode("", $html))
                    ->plain()
                    ->setWidth(8, 3);
            }
            $form->textarea('comment', exmtrans("common.comment"))
                ->rows(3)
                ->required()
                ->setLabelClass(['d-none'])
                ->setWidth(12, 0);
            $row->column(6, (new Box(exmtrans("common.comment"), $form))->style('info'));
        }
    }
    
    /**
     * compare
     */
    public function compare(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        $this->AdminContent($content);
        $content->body($this->getRevisionCompare($id, $request->get('revision')));
        return $content;
    }
   
    /**
     * get compare item for pjax
     */
    public function compareitem(Request $request, Content $content, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        return $this->getRevisionCompare($id, $request->get('revision'), true);
    }
   
    /**
     * restore data
     */
    public function restoreRevision(Request $request, $tableKey, $id)
    {
        $this->firstFlow($request, $id);
        
        $revision_suuid = $request->get('revision');
        $custom_value = $this->getModelNameDV()::find($id);
        $custom_value->setRevision($revision_suuid)->save();
        return redirect($custom_value->getUrl());
    }
  
    /**
     * get revision compare.
     */
    protected function getRevisionCompare($id, $revision_suuid = null, $pjax = false)
    {
        $table_name = $this->custom_table->table_name;
        // get all revisions
        $revisions = $this->getRevisions($id, false, true);
        $newest_revision = $revisions->first();
        $newest_revision_suuid = $newest_revision->suuid;
        if (!isset($revision_suuid)) {
            $revision_suuid = $newest_revision_suuid ?? null;
        }

        // create revision value
        $old_revision = Revision::findBySuuid($revision_suuid);
        $revision_value = $this->getModelNameDV()::find($id)->setRevision($revision_suuid);
        $custom_value = $this->getModelNameDV()::find($id);

        // set table columns
        $table_columns = [];
        foreach ($this->custom_table->custom_columns as $custom_column) {
            $revision_value_column = $revision_value->getValue($custom_column, true);
            $custom_value_column = $custom_value->getValue($custom_column, true);

            $table_columns[] = [
                'old_value' => $revision_value_column,
                'new_value' => $custom_value_column,
                'diff' => $revision_value_column != $custom_value_column,
                'label' => $custom_column->column_view_name,
            ];
        }

        $prms = [
            'change_page_menu' => (new Tools\GridChangePageMenu('data', $this->custom_table, false))->render(),
            'revisions' => $revisions,
            'custom_value' => $custom_value,
            'table_columns' => $table_columns,
            'newest_revision' => $newest_revision,
            'newest_revision_suuid' => $newest_revision_suuid,
            'old_revision' => $old_revision,
            'revision_suuid' => $revision_suuid,
            'has_edit_permission' => $this->custom_table->hasPermissionEditData($id),
            'form_url' => admin_urls('data', $table_name, $id, 'compare'),
            'has_diff' => collect($table_columns)->filter(function ($table_column) {
                return array_get($table_column, 'diff', false);
            })->count() > 0
        ];

        if ($pjax) {
            return view("exment::custom-value.revision-compare-inner", $prms);
        }
        
        $script = <<<EOT
        $("#revisions").off('change').on('change', function(e, params) {
            var url = admin_url(URLJoin('data', '$table_name', '$id', 'compare'));
            var query = {'revision': $(e.target).val()};

            $.pjax({container:'#pjax-container-revision', url: url +'?' + $.param(query) });
        });
    
EOT;
        Admin::script($script);
        
        return view("exment::custom-value.revision-compare", $prms);
    }
    

    /**
     * whether file upload field
     */
    protected function useFileUpload($modal = false)
    {
        // if no permission, return
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return [];
        }
        
        return !$modal && boolval($this->custom_table->getOption('attachment_flg') ?? true);
    }
    
    /**
     * whether comment field
     */
    protected function useComment($modal = false)
    {
        return !$modal && boolval($this->custom_table->getOption('comment_flg') ?? true);
    }

    protected function getDocuments($id, $modal = false)
    {
        if ($modal) {
            return [];
        }
        return getModelName(SystemTableName::DOCUMENT)
            ::where('parent_id', $id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }
    
    protected function getComments($id, $modal = false)
    {
        if ($modal) {
            return [];
        }
        return getModelName(SystemTableName::COMMENT)
            ::where('parent_id', $id)
            ->where('parent_type', $this->custom_table->table_name)
            ->get();
    }

    /**
     * get target data revisions
     */
    protected function getRevisions($id, $modal = false, $all = false)
    {
        if ($modal || !boolval($this->custom_table->getOption('revision_flg'))) {
            return [];
        }

        // if no permission, return
        if (!$this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE)) {
            return [];
        }
        
        $query = $this->getModelNameDV()::find($id)
            ->revisionHistory()
            ->orderby('id', 'desc');
        
        // if not all
        if (!$all) {
            $query = $query->take(10);
        }
        return $query->get() ?? [];
    }
}
