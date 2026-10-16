(function ($) {
    'use strict';

    function setInline(help, inline) {
        help.classList.toggle('form-help-inline', inline);
        help.parentElement.classList.toggle('form-help-wrap', inline);
    }

    function positionHelp(module) {
        $(module).find('.form-help').each(function () {
            if (this.closest('.data-module') !== module || this.classList.contains('form-help-dismissed')) return;

            setInline(this, false);
            if (!this.getClientRects().length) return;
            if (getComputedStyle(this).position === 'static') {
                setInline(this, true);
                return;
            }

            // The activity form and preview can scroll inside their own containers.
            let top = 8;
            let left = 8;
            let right = document.documentElement.clientWidth - 8;
            for (let parent = this.parentElement; parent; parent = parent.parentElement) {
                const style = getComputedStyle(parent);
                const bounds = parent.getBoundingClientRect();
                if (/(auto|scroll|hidden|clip)/.test(style.overflowY)) {
                    top = Math.max(top, bounds.top + parent.clientTop + 8);
                }
                if (/(auto|scroll|hidden|clip)/.test(style.overflowX)) {
                    left = Math.max(left, bounds.left + parent.clientLeft + 8);
                    right = Math.min(right, bounds.left + parent.clientLeft + parent.clientWidth - 8);
                }
            }

            const bounds = this.getBoundingClientRect();
            setInline(this, bounds.top < top || bounds.left < left || bounds.right > right);
        });
    }

    // Delegation also covers fields loaded after changing the activity type.
    $(document).on('mouseenter focusin', '.data-module', function () {
        positionHelp(this);
    });

    $(document).on('mouseleave focusout', '.data-module', function () {
        const module = this;
        requestAnimationFrame(function () {
            if (!module.matches(':hover') && !module.contains(document.activeElement)) {
                $(module).find('.form-help').removeClass('form-help-dismissed').each(function () {
                    setInline(this, false);
                });
            }
        });
    });

    $(document).on('keydown', function (event) {
        if (event.key !== 'Escape') return;
        $('.form-help:visible').addClass('form-help-dismissed').each(function () {
            setInline(this, false);
        });
    });

    let scheduled = false;
    function updateVisibleHelp() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(function () {
            scheduled = false;
            $('.data-module:hover, .data-module:focus-within').each(function () {
                positionHelp(this);
            });
        });
    }

    window.addEventListener('resize', updateVisibleHelp);
    // Capture scroll events from the content wrapper and modal as well as the page.
    document.addEventListener('scroll', updateVisibleHelp, true);
})(jQuery);
