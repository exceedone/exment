<?php

namespace Exceedone\Exment\Form\Field;

use ExmentAdminCore\Admin\Form\Field;

/**
 * Section navigation (table of contents) for a long form.
 *
 * Every exmheader on the same level as this field starts a section. Each
 * section is put in a box of its own - the header's title in the box head
 * with a small "back to top" link, the fields that follow it in the box
 * body - and the nav lists the sections as buttons that scroll to them.
 * The nav stays at the top of the screen while the page scrolls.
 *
 * The fields are only moved, never rebuilt: their names, values, scripts
 * and the way the form is sent stay as they were.
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
        if (\$nav.data('exmSectioned')) {
            return;
        }
        \$nav.data('exmSectioned', true);

        var \$list = \$nav.find('.exm-section-nav-list').empty();
        var \$container = \$nav.parent();
        // Only the headers on the nav's own level: a header inside a child
        // block (has-many, embeds) belongs to that block.
        var \$groups = \$container.children('.form-group').filter(function () {
            return $(this).find('.field-header').length > 0;
        });

        \$groups.each(function (index) {
            var \$group = $(this);
            var title = $.trim(\$group.find('.field-header').first().text());
            var id = 'exm-section-' + (index + 1);

            var \$box = $('<div class="box exm-section-box"></div>').attr('id', id);
            var \$head = $('<div class="box-header with-border exm-section-head"></div>');
            $('<h3 class="box-title"></h3>').text(title).appendTo(\$head);
            $('<a href="#" class="exm-section-nav-back small"></a>')
                .text({$backLabel})
                .on('click', function (e) {
                    e.preventDefault();
                    \$nav[0].scrollIntoView({behavior: 'smooth', block: 'start'});
                })
                .appendTo(\$head);

            var \$body = $('<div class="box-body exm-section-body"></div>');
            \$body.append(\$group.nextUntil(\$groups));
            \$body.children('hr').first().remove();

            \$box.append(\$head).append(\$body);
            \$group.replaceWith(\$box);

            $('<button type="button" class="btn btn-sm btn-default exm-section-nav-item"></button>')
                .text(title)
                .on('click', function () {
                    document.getElementById(id).scrollIntoView({behavior: 'smooth', block: 'start'});
                })
                .appendTo(\$list);
        });

        \$container.toggleClass('exm-sectioned', \$groups.length > 0);
        \$nav.toggle(\$groups.length > 1);

        // Keep the nav on screen. position: sticky does not hold inside the
        // admin layout (an ancestor clips it), so the nav is fixed to the top
        // once its place has scrolled away, with a spacer keeping the height.
        if (\$groups.length > 1 && window.IntersectionObserver) {
            var \$mark = $('<div class="exm-section-nav-mark"></div>').insertBefore(\$nav);
            var \$spacer = $('<div class="exm-section-nav-spacer"></div>').hide().insertAfter(\$nav);
            var place = function () {
                var r = \$container[0].getBoundingClientRect();
                \$nav.css({left: r.left, width: r.width});
            };
            new IntersectionObserver(function (entries) {
                var away = !entries[0].isIntersecting && entries[0].boundingClientRect.top < 0;
                if (away === \$nav.hasClass('exm-section-nav-fixed')) {
                    return;
                }
                \$spacer.height(away ? \$nav.outerHeight(true) : 0).toggle(away);
                \$nav.toggleClass('exm-section-nav-fixed', away);
                if (away) {
                    place();
                } else {
                    \$nav.css({left: '', width: ''});
                }
            }).observe(\$mark[0]);
            $(window).on('resize', function () {
                if (\$nav.hasClass('exm-section-nav-fixed')) {
                    place();
                }
            });
        }
    });
})();
SCRIPT;
    }
}
