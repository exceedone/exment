/**
 * Scroll position restore for custom table list view
 * Handles scroll restoration on browser back button and pjax navigation
 */
var Exment;
(function (Exment) {
    'use strict';

    const CLASSNAME_CUSTOM_VALUE_GRID = '.block_custom_value_grid';

    // Save current scroll position
    function saveCurrentScrollPosition() {
        const scrollTop = $(window).scrollTop() || document.documentElement.scrollTop || document.body.scrollTop || 0;
        
        // Store in history state if available
        if (window.history && window.history.replaceState) {
            const currentState = window.history.state || {};
            currentState.scrollTop = scrollTop;
            try {
                window.history.replaceState(currentState, document.title);
            } catch (e) {
                console.warn('Failed to update history state:', e);
            }
        }
    }

    // Restore scroll position from history state
    function restoreScrollPosition() {
        let scrollTop = 0;
        
        // Only restore from history state (for back button)
        if (window.history && window.history.state && typeof window.history.state.scrollTop === 'number') {
            scrollTop = window.history.state.scrollTop;
        }
        
        if (scrollTop > 0) {
            // Use setTimeout to ensure DOM is ready
            setTimeout(function() {
                window.scrollTo(0, scrollTop);
                // Verify and retry if needed
                setTimeout(function() {
                    const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
                    if (Math.abs(currentScroll - scrollTop) > 50) {
                        window.scrollTo(0, scrollTop);
                    }
                }, 100);
            }, 0);
        }
    }

    // ScrollRestore module
    Exment.ScrollRestore = {
        init: function() {
            // Prevent multiple initialization
            if (window.exmentScrollRestoreInitialized) {
                return;
            }
            window.exmentScrollRestoreInitialized = true;

            // Disable browser's default scroll restoration
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }

            restoreScrollPosition();
            
            // Save scroll position periodically while scrolling
            let scrollTimer;
            $(window).on('scroll', function() {
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(function() {
                    // Only save if we're on a list page (grid view)
                    if ($(CLASSNAME_CUSTOM_VALUE_GRID).length > 0) {
                        saveCurrentScrollPosition();
                    }
                }, 150);
            });

            // Save scroll position before leaving page
            $(window).on('beforeunload', function() {
                if ($(CLASSNAME_CUSTOM_VALUE_GRID).length > 0) {
                    saveCurrentScrollPosition();
                }
            });

            // Handle pjax events
            if ($.pjax) {
                let isPjaxPopstate = false;

                $(document).on('pjax:popstate', function() {
                    isPjaxPopstate = true;
                });

                // Save scroll position before pjax request
                $(document).on('pjax:send', function(event, xhr, options) {
                    if ($(CLASSNAME_CUSTOM_VALUE_GRID).length > 0) {
                        saveCurrentScrollPosition();
                    }
                });

                // Handle scroll position after pjax complete
                $(document).on('pjax:end', function(event, xhr, options) {
                    if ($(CLASSNAME_CUSTOM_VALUE_GRID).length > 0) {
                        if (isPjaxPopstate) {
                            // If it's a back/forward navigation, restore scroll
                            restoreScrollPosition();
                            isPjaxPopstate = false;
                        } else {
                            // If it's a new navigation (pagination, sort, etc), scroll to top
                            window.scrollTo(0, 0);
                        }
                    }
                });
            }

            // Save position when clicking on links in grid
            $(CLASSNAME_CUSTOM_VALUE_GRID).on('click', 'a', function() {
                saveCurrentScrollPosition();
            });
        }
    };

    // Auto-initialize on document ready
    $(function() {
        Exment.ScrollRestore.init();
    });

})(Exment || (Exment = {}));
