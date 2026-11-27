/* llmMemoryEditor: inline edit, add and delete of memory items.
 *
 * The markup is rendered by the server (tpl_llmMemoryEditor_body.php); this script only reacts to
 * clicks and posts the change to the page. A section shows one body per memory key; a change is
 * posted with the key of its body, and the answer carries the new body of that key, which replaces
 * the old one - so the memory version and CSRF token on the body are always the current ones. Handlers are delegated from the document: they keep working when the page replaces a
 * section after a refresh (refresh_sections). Plain jQuery, hand-written (not part of a build).
 *
 * Layouts: the accordion panels and tabs are Bootstrap 4 markup that Bootstrap's own data API
 * opens and closes. Because the whole body is replaced after an action, this script only notes the
 * open panel / active tab (by its section code) before and sets it again on the new body. */
(function ($) {
    'use strict';

    var BODY = '.llm-memory-editor-body';

    /** The code of the section the accordion panel / tab that is open on the body shows: '' when every accordion
     *  panel is closed, undefined in the other layouts. */
    function openSection($body) {
        if ($body.hasClass('lme-layout-accordion')) {
            var code = $body.find('.lme-section-panel.show, .lme-section-panel.collapsing').closest('.lme-section').attr('data-section');
            return code === undefined ? '' : code;
        }
        if ($body.hasClass('lme-layout-tabs')) {
            return $body.find('.lme-tab.active').attr('data-section');
        }
        return undefined;
    }

    /** Open the panel / tab of a section on a body that is not in the page yet (no animation, no flicker).
     *  '' closes every accordion panel; a section that is gone leaves the server's choice. */
    function showSection($body, code) {
        if (code === undefined) {
            return;
        }
        var $section = $body.find('.lme-section').filter(function () {
            return code !== '' && $(this).attr('data-section') === code;
        });
        if (!$section.length && !(code === '' && $body.hasClass('lme-layout-accordion'))) {
            return;
        }
        if ($body.hasClass('lme-layout-accordion')) {
            $body.find('.lme-section-panel').removeClass('show');
            $body.find('.lme-section-toggle').addClass('collapsed').attr('aria-expanded', 'false');
            $section.find('.lme-section-panel').addClass('show');
            $section.find('.lme-section-toggle').removeClass('collapsed').attr('aria-expanded', 'true');
        } else if ($body.hasClass('lme-layout-tabs')) {
            $body.find('.lme-tab').removeClass('active').attr('aria-selected', 'false');
            $body.find('.lme-tab').filter(function () {
                return $(this).attr('data-section') === code;
            }).addClass('active').attr('aria-selected', 'true');
            $body.find('.tab-pane').removeClass('show active');
            $section.addClass('show active');
        }
    }

    /** Show a message on top of the body. */
    function showMessage($body, text) {
        $body.find('.lme-message').first()
            .removeClass('alert-info alert-warning alert-success')
            .addClass('alert-danger')
            .text(text)
            .prop('hidden', false);
    }

    /** Close every open inline form of the body and show the rows and buttons it hid. */
    function closeForms($body) {
        $body.find('.lme-item .lme-edit-form, .lme-add .lme-edit-form').each(function () {
            var $form = $(this);
            var $parent = $form.parent();
            $parent.find('.lme-text, .lme-actions, .lme-add-btn').show();
            $form.remove();
        });
    }

    /** A new inline form (input with save and cancel), cloned from the template of the body. */
    function newForm($body, value, label) {
        var $form = $body.find('.lme-form-template .lme-edit-form').first().clone();
        $form.find('.lme-input').val(value).attr('aria-label', label);
        return $form;
    }

    function startEdit($body, $item) {
        closeForms($body);
        var $form = newForm($body, $item.find('.lme-text').text(), $item.find('.lme-edit').text());
        $item.find('.lme-text, .lme-actions').hide();
        $item.append($form);
        $form.find('.lme-input').trigger('focus');
    }

    function startAdd($body, $add) {
        closeForms($body);
        var $form = newForm($body, '', $add.find('.lme-add-btn').text());
        $add.find('.lme-add-btn').hide();
        $add.append($form);
        $form.find('.lme-input').trigger('focus');
    }

    /** Post an action to the page; the section body is replaced by what the answer carries. */
    function post($body, action, data) {
        $body.addClass('lme-busy').attr('aria-busy', 'true');
        $.ajax({
            url: window.location.href.split('#')[0],
            type: 'POST',
            dataType: 'json',
            data: $.extend({
                __llm_memory_action: action,
                section_id: $body.attr('data-section-id'),
                memory_key: $body.attr('data-memory-key'),
                memory_version: $body.attr('data-memory-version'),
                csrf_token: $body.attr('data-csrf-token')
            }, data)
        }).done(function (response) {
            answer($body, response);
        }).fail(function (xhr) {
            answer($body, xhr.responseJSON || { message: $body.attr('data-error-text') });
        });
    }

    function answer($body, response) {
        if (response && response.html) {
            var $new = $($.parseHTML($.trim(response.html)));
            showSection($new, openSection($body));
            $body.replaceWith($new);
            return;
        }
        $body.removeClass('lme-busy').removeAttr('aria-busy');
        showMessage($body, (response && response.message) || $body.attr('data-error-text'));
    }

    function save($body, $form) {
        var text = $.trim($form.find('.lme-input').val());
        var $item = $form.closest('.lme-item');
        if ($item.length) {
            post($body, 'edit_item', { item_id: $item.attr('data-item-id'), text: text });
        } else {
            post($body, 'add_item', { section: $form.closest('.lme-add').attr('data-section'), text: text });
        }
    }

    function confirmDelete($body, $button) {
        var confirmation = $button.data('confirmation') || {};
        var run = function () {
            post($body, 'delete_item', { item_id: $button.closest('.lme-item').attr('data-item-id') });
        };
        if (typeof $.confirm !== 'function') {
            if (window.confirm(confirmation.confirmation_message || '')) {
                run();
            }
            return;
        }
        $.confirm({
            type: 'red',
            title: confirmation.confirmation_title || '',
            content: confirmation.confirmation_message || '',
            buttons: {
                confirm: {
                    text: confirmation.confirmation_continue || 'OK',
                    action: run
                },
                cancel: {
                    text: confirmation.label_cancel || 'Cancel',
                    action: function () { }
                }
            }
        });
    }

    $(document).on('click', BODY + ' [data-lme-action]', function () {
        var $button = $(this);
        var $body = $button.closest(BODY);
        if ($body.hasClass('lme-busy')) {
            return;
        }
        switch ($button.attr('data-lme-action')) {
            case 'edit':
                startEdit($body, $button.closest('.lme-item'));
                break;
            case 'add':
                startAdd($body, $button.closest('.lme-add'));
                break;
            case 'delete':
                confirmDelete($body, $button);
                break;
            case 'save':
                save($body, $button.closest('.lme-edit-form'));
                break;
            case 'cancel':
                closeForms($body);
                break;
        }
    });

    $(document).on('keydown', BODY + ' .lme-input', function (event) {
        var $body = $(this).closest(BODY);
        if (event.which === 13) {
            event.preventDefault();
            if (!$body.hasClass('lme-busy')) {
                save($body, $(this).closest('.lme-edit-form'));
            }
        } else if (event.which === 27) {
            closeForms($body);
        }
    });
})(jQuery);
