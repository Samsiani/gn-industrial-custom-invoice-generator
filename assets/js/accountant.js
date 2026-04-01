jQuery(function($) {
    'use strict';

    if ($('.cig-accountant-wrapper').length) {
        
        // --- State Variables ---
        var accCurrentPage = 1;
        var filterCompletion = 'all'; 
        var filterType = 'all';
        var filterDateFrom = '';
        var filterDateTo = '';
        var searchQuery = '';
        var searchTimeout = null;
        var currentInvoiceId = 0;
        
        // Modal State
        var pendingCheckbox = null;
        var pendingStatusType = '';
        var pendingTargetState = false; 

        // --- NEW: Toggle Reset Button Visibility ---
        function checkResetVisibility() {
            var isDefault = (
                filterCompletion === 'all' && 
                filterType === 'all' && 
                filterDateFrom === '' && 
                filterDateTo === '' && 
                searchQuery === ''
            );

            if (isDefault) {
                $('#cig-reset-filters').fadeOut(200);
            } else {
                $('#cig-reset-filters').fadeIn(200).css('display', 'inline-flex');
            }
        }

        // --- ADAPTIVE FILTER LOGIC ---
        function updateAdaptiveFilters() {
            var $dropdown = $('#cig-acc-type-filter');
            
            // თუ არჩეულია "დაუსრულებელი", დროფდაუნი გადავიყვანოთ "All"-ზე,
            // მაგრამ არ გავთიშოთ (Disabled არ ვუკეთებთ).
            if (filterCompletion === 'incomplete') {
                if ($dropdown.val() !== 'all') {
                    $dropdown.val('all');
                    filterType = 'all';
                }
            }
            
            // ვამოწმებთ Reset ღილაკის გამოჩენას
            checkResetVisibility();
        }

        // --- Load Data ---
        function loadAccountantInvoices(page) {
            page = page || 1;
            
            // ყოველი ჩატვირთვის წინ ვამოწმებთ ღილაკის სტატუსს
            checkResetVisibility(); 

            $('#cig-acc-tbody').html('<tr><td colspan="12" style="text-align:center;padding:30px;"><div class="cig-loading-spinner" style="display:inline-block; border:3px solid #f3f3f3; border-top:3px solid #50529d; border-radius:50%; width:20px; height:20px; animation:spin 1s linear infinite; vertical-align:middle; margin-right:10px;"></div> ' + (cigAjax.i18n?.loading || 'Loading...') + '</td></tr>');

            $.ajax({
                url: cigAjax.ajax_url, 
                method: 'POST', 
                dataType: 'json',
                data: {
                    action: 'cig_get_accountant_invoices',
                    nonce: cigAjax.nonce,
                    paged: page,
                    completion: filterCompletion,
                    type_filter: filterType,
                    date_from: filterDateFrom,
                    date_to: filterDateTo,
                    search: searchQuery
                },
                success: function(res) {
                    if (res.success && res.data) {
                        renderAccountantTable(res.data.invoices);
                        renderPagination(res.data.current_page, res.data.total_pages);
                        accCurrentPage = res.data.current_page;
                    } else {
                        $('#cig-acc-tbody').html('<tr><td colspan="12" style="text-align:center;padding:20px;">' + (cigAjax.i18n?.no_invoices_found || 'No invoices found.') + '</td></tr>');
                        $('#cig-acc-pagination').empty();
                    }
                },
                error: function() {
                    $('#cig-acc-tbody').html('<tr><td colspan="12" style="text-align:center;color:#dc3545;padding:20px;">' + (cigAjax.i18n?.error_loading_data || 'Error loading data.') + '</td></tr>');
                }
            });
        }

        // --- Render Table ---
        function renderAccountantTable(invoices) {
            if (!invoices || invoices.length === 0) {
                $('#cig-acc-tbody').html('<tr><td colspan="12" style="text-align:center;padding:20px;">' + (cigAjax.i18n?.no_invoices_found || 'No invoices found.') + '</td></tr>');
                return;
            }

            var html = '';
            
            invoices.forEach(function(inv) {
                var currentStatus = inv.status;

                var makeChk = function(type) {
                    var isChecked = (currentStatus === type) ? 'checked' : '';
                    return '<td style="text-align:center; vertical-align:middle;">' +
                           '<input type="checkbox" class="cig-status-chk" data-type="'+type+'" data-id="'+inv.id+'" '+isChecked+'>' +
                           '</td>';
                };

                var colInv = '<a href="' + inv.view_url + '" target="_blank" style="font-weight:700; color:#50529d; display:block; font-size:14px;">' + inv.number + '</a>' +
                             '<span style="font-size:11px; color:#888;">' + inv.date + '</span>';

                // Sold Date column
                var colSoldDate = inv.sold_date ? '<span style="font-size:13px; color:#333;">' + inv.sold_date + '</span>' : '<span style="color:#ccc;">—</span>';

                var colClient = '<div style="font-weight:600; color:#333;">' + (inv.client_name || '—') + '</div>';
                if (inv.client_tax) colClient += '<div style="font-size:11px; color:#666;">ID: ' + inv.client_tax + '</div>';

                var colPay = '<div style="font-size:13px; font-weight:500;">' + (inv.payment_title || '—') + '</div>';
                if (inv.payment_desc) colPay += '<div style="font-size:10px; color:#777; margin-top:2px;">' + inv.payment_desc + '</div>';

                var colTotal = '';
                if (inv.original_total) {
                    colTotal = '<del style="color:#999;font-size:11px;font-weight:normal;">' + inv.original_total + '</del> <strong style="font-size:13px;">' + inv.total + '</strong>';
                } else {
                    colTotal = '<strong style="font-size:13px;">' + inv.total + '</strong>';
                }
                if (inv.is_partial) colTotal += '<div style="font-size:9px;color:#e0a800;font-weight:bold;text-transform:uppercase;">(' + (cigAjax.i18n?.partial || 'Partial') + ')</div>';

                var btnAttrs = 'data-id="' + inv.id + '" ' + 
                               'data-num="' + inv.number + '" ' + 
                               'data-anote="' + escapeHtml(inv.acc_note || '') + '" ' + 
                               'data-cnote="' + escapeHtml(inv.consultant_note || '') + '"';

                var noteIcons = [];
                if (inv.consultant_note) {
                    noteIcons.push('<span class="dashicons dashicons-admin-users cig-note-icon cig-view-note" ' + btnAttrs + ' title="' + (cigAjax.i18n?.view_consultant_note || 'View Consultant Note') + '" style="color:#50529d;"></span>');
                }
                if (inv.acc_note) {
                    noteIcons.push('<span class="dashicons dashicons-format-chat cig-note-icon cig-view-note" ' + btnAttrs + ' title="' + (cigAjax.i18n?.view_accountant_note || 'View Accountant Note') + '" style="color:#28a745;"></span>');
                }
                var colNote = noteIcons.length > 0 ? '<div class="cig-note-wrapper">' + noteIcons.join('') + '</div>' : '<span style="color:#eee;">—</span>';

                var colAct = '<button type="button" class="cig-edit-note" ' + btnAttrs + ' style="background:none; border:none; cursor:pointer; color:#777;" title="' + (cigAjax.i18n?.edit_note || 'Edit Note') + '"><span class="dashicons dashicons-admin-generic" style="font-size:18px;"></span></button>';

                html += '<tr>';
                html += '<td>' + colInv + '</td>';
                html += '<td>' + colSoldDate + '</td>';
                html += '<td>' + colClient + '</td>';
                html += '<td>' + colPay + '</td>';
                html += '<td>' + colTotal + '</td>';
                html += makeChk('rs');
                html += makeChk('credit');
                html += makeChk('receipt');
                html += makeChk('corrected');
                html += '<td style="text-align:center;">' + colNote + '</td>';
                html += '<td style="text-align:center;">' + colAct + '</td>';
                html += '<td style="text-align:center;"><a href="' + inv.view_url + '" target="_blank" style="color:#555; text-decoration:none;"><span class="dashicons dashicons-visibility"></span></a></td>';
                html += '</tr>';
            });
            $('#cig-acc-tbody').html(html);
        }

        function renderPagination(current, total) {
            var $pag = $('#cig-acc-pagination'); $pag.empty(); 
            if (total <= 1) return;
            if (current > 1) $pag.append('<button class="cig-acc-page-btn" data-page="' + (current - 1) + '">« ' + (cigAjax.i18n?.prev || 'Prev') + '</button>');
            var pageText = (cigAjax.i18n?.page_of || 'Page %1$s of %2$s').replace('%1$s', current).replace('%2$s', total);
            $pag.append('<span style="font-size:13px;color:#555;padding:0 10px;">' + pageText + '</span>');
            if (current < total) $pag.append('<button class="cig-acc-page-btn" data-page="' + (current + 1) + '">' + (cigAjax.i18n?.next || 'Next') + ' »</button>');
        }

        // --- 3. Filter Events & Adaptive Logic ---

        // RESET BUTTON
        $(document).on('click', '#cig-reset-filters', function() {
            // Reset vars
            filterCompletion = 'all';
            filterType = 'all';
            filterDateFrom = '';
            filterDateTo = '';
            searchQuery = '';

            // Reset UI
            $('#cig-acc-search').val('');
            $('#cig-acc-date-from').val('');
            $('#cig-acc-date-to').val('');
            $('#cig-acc-type-filter').val('all');
            
            // Set Toggle to All
            $('input[name="cig_completion"][value="all"]').prop('checked', true);
            $('.cig-toggle-btn').removeClass('active');
            $('input[name="cig_completion"][value="all"]').parent().addClass('active');

            // Reset Date Filters
            $('.cig-qf-btn').removeClass('active');
            $('.cig-qf-btn[data-range="all"]').addClass('active');

            // Enable dropdown just in case
            $('#cig-acc-type-filter').prop('disabled', false).removeClass('cig-disabled-input');

            // Load
            loadAccountantInvoices(1);
        });

        // Toggle Change (Primary Filter)
        $(document).on('change', 'input[name="cig_completion"]', function() {
            filterCompletion = $(this).val();
            $('.cig-toggle-btn').removeClass('active');
            $(this).parent().addClass('active');
            
            // Logic 1: If "Incomplete" is selected, Reset Dropdown to 'All'
            if (filterCompletion === 'incomplete') {
                $('#cig-acc-type-filter').val('all');
                filterType = 'all';
            }

            // Note: We are NOT disabling the dropdown anymore.
            updateAdaptiveFilters();
            loadAccountantInvoices(1);
        });

        // Dropdown Change (Secondary Filter)
        $(document).on('change', '#cig-acc-type-filter', function() {
            filterType = $(this).val();
            
            // Logic 2: If a specific status is selected, switch Toggle to 'Completed' 
            // (because if it has a status, it's considered processed/completed contextually)
            if (filterType !== 'all') {
                if (filterCompletion === 'incomplete') {
                    filterCompletion = 'completed';
                    $('input[name="cig_completion"][value="completed"]').prop('checked', true);
                    
                    // Update Visuals
                    $('.cig-toggle-btn').removeClass('active');
                    $('input[name="cig_completion"][value="completed"]').parent().addClass('active');
                }
            }

            checkResetVisibility();
            loadAccountantInvoices(1);
        });

        // Date & Search
        $(document).on('click', '.cig-qf-btn', function() {
            var range = $(this).data('range'); 
            $('.cig-qf-btn').removeClass('active'); $(this).addClass('active');
            var from = '', to = ''; var today = new Date(); var fmt = function(d) { var year = d.getFullYear(); var month = String(d.getMonth() + 1).padStart(2, '0'); var day = String(d.getDate()).padStart(2, '0'); return year + '-' + month + '-' + day; };
            
            if (range === 'today') { from = fmt(today); to = fmt(today); }
            else if (range === 'yesterday') { var d = new Date(today); d.setDate(d.getDate() - 1); from = fmt(d); to = fmt(d); }
            else if (range === 'week') { var d = new Date(today); var day = d.getDay() || 7; if(day !== 1) d.setHours(-24 * (day - 1)); from = fmt(d); to = fmt(today); }
            else if (range === 'month') { var d = new Date(today.getFullYear(), today.getMonth(), 1); from = fmt(d); to = fmt(today); }
            else if (range === 'all') { from = ''; to = ''; }
            
            $('#cig-acc-date-from').val(from); $('#cig-acc-date-to').val(to); 
            filterDateFrom = from; filterDateTo = to; 
            
            checkResetVisibility();
            loadAccountantInvoices(1);
        });
        
        $(document).on('change', '#cig-acc-date-from, #cig-acc-date-to', function() {
            $('.cig-qf-btn').removeClass('active');
            filterDateFrom = $('#cig-acc-date-from').val();
            filterDateTo = $('#cig-acc-date-to').val();
            checkResetVisibility();
            loadAccountantInvoices(1);
        });

        $(document).on('input', '#cig-acc-search', function() {
            var val = $(this).val().trim();
            if (searchTimeout) clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() { 
                searchQuery = val; 
                checkResetVisibility();
                loadAccountantInvoices(1); 
            }, 400);
        });

        $(document).on('click', '.cig-acc-page-btn', function() {
            loadAccountantInvoices(parseInt($(this).data('page')));
        });

        // --- 4. Checkbox Logic ---
        
        $(document).on('click', '.cig-status-chk', function(e) {
            var desiredState = $(this).prop('checked');
            e.preventDefault(); 
            
            var $chk = $(this);
            var id = $chk.data('id');
            var type = $chk.data('type');

            pendingCheckbox = $chk;
            pendingStatusType = type;
            pendingTargetState = desiredState;
            currentInvoiceId = id;

            var msg = '';
            var label = getStatusLabel(type);
            var row = $chk.closest('tr');
            var anyOtherChecked = row.find('.cig-status-chk:checked').not($chk).length > 0;

            if (desiredState) { 
                if (anyOtherChecked) {
                    msg = 'ამ ინვოისს უკვე აქვს სხვა სტატუსი. გსურთ შეცვალოთ ის "' + label + '"-ით?';
                } else {
                    msg = 'გსურთ მიანიჭოთ სტატუსი: "' + label + '"?';
                }
            } else { 
                msg = 'ნამდვილად გსურთ სტატუსის (' + label + ') გაუქმება? ინვოისი გადავა "დაუსრულებელში".';
            }

            $('#cig-confirm-msg').text(msg);
            $('#cig-confirm-modal').fadeIn(200);
        });

        // Confirm YES
        $(document).on('click', '#cig-confirm-yes', function() {
            $('#cig-confirm-modal').fadeOut(200);
            
            if (!pendingCheckbox) return;
            var $chk = pendingCheckbox;
            var row = $chk.closest('tr');

            if (pendingTargetState) {
                row.find('.cig-status-chk').prop('checked', false);
                $chk.prop('checked', true);
            } else {
                $chk.prop('checked', false);
            }

            $.ajax({
                url: cigAjax.ajax_url, 
                method: 'POST', 
                dataType: 'json',
                data: {
                    action: 'cig_set_invoice_status',
                    nonce: cigAjax.nonce,
                    invoice_id: currentInvoiceId,
                    status_type: pendingStatusType,
                    state: pendingTargetState ? 'true' : 'false'
                },
                success: function(res) {},
                error: function() {
                    alert(cigAjax.i18n?.error_updating_status || 'Error updating status');
                    $chk.prop('checked', !pendingTargetState);
                }
            });
        });

        $(document).on('click', '#cig-confirm-no', function() {
            $('#cig-confirm-modal').fadeOut(200);
        });


        // --- 5. Note Modal Logic ---

        function populateModal(triggerBtn) {
            currentInvoiceId = triggerBtn.data('id');
            var num = triggerBtn.data('num');
            var cNote = triggerBtn.data('cnote');
            var aNote = triggerBtn.data('anote');

            $('#cig-modal-invoice-num').text('#' + num);
            
            var $cDisplay = $('#cig-consultant-display');
            if (cNote && cNote.trim() !== '') {
                $cDisplay.text(cNote).css({'color':'#333', 'font-style':'normal'});
            } else {
                $cDisplay.text(cigAjax.i18n?.no_consultant_note || '(No consultant note)').css({'color':'#999', 'font-style':'italic'});
            }
            
            $('#cig-acc-note-input').val(aNote || '');
        }

        $(document).on('click', '.cig-view-note', function() {
            populateModal($(this));
            $('#cig-acc-note-input').prop('readonly', true).css({'background': '#f9f9f9', 'border': 'none', 'resize': 'none', 'box-shadow': 'none'});
            $('#cig-save-note').hide();
            $('#cig-note-modal').fadeIn(200);
        });

        $(document).on('click', '.cig-edit-note', function() {
            populateModal($(this));
            $('#cig-acc-note-input').prop('readonly', false).css({'background': '#fff', 'border': '1px solid #aaa', 'resize': 'vertical', 'box-shadow': 'inset 0 1px 2px rgba(0,0,0,0.07)'});
            $('#cig-save-note').show();
            $('#cig-note-modal').fadeIn(200);
        });

        $(document).on('click', '#cig-save-note', function() {
            var note = $('#cig-acc-note-input').val();
            var $btn = $(this);
            $btn.prop('disabled', true).text(cigAjax.i18n?.saving || 'Saving...');
            
            $.ajax({
                url: cigAjax.ajax_url, 
                method: 'POST', 
                dataType: 'json',
                data: { action: 'cig_update_accountant_note', nonce: cigAjax.nonce, invoice_id: currentInvoiceId, note: note },
                success: function(res) {
                    $btn.prop('disabled', false).text(cigAjax.i18n?.save_note || 'Save Note');
                    if(res.success) {
                        $('#cig-note-modal').fadeOut(200);
                        loadAccountantInvoices(accCurrentPage);
                    } else { alert(cigAjax.i18n?.error_saving_note || 'Error saving note'); }
                },
                error: function() { $btn.prop('disabled', false).text(cigAjax.i18n?.save_note || 'Save Note'); alert(cigAjax.i18n?.connection_error || 'Connection error'); }
            });
        });

        $('.cig-modal-close').on('click', function(){ $(this).closest('.cig-modal').fadeOut(200); });
        $(window).on('click', function(e) { if ($(e.target).hasClass('cig-modal')) $(e.target).fadeOut(200); });
        function getStatusLabel(type) { 
            var map = { 
                'rs': cigAjax.i18n?.rs_uploaded || 'RS Uploaded', 
                'credit': cigAjax.i18n?.credit_installment || 'Credit / Installment', 
                'receipt': cigAjax.i18n?.receipt_full || 'Full Receipt', 
                'corrected': cigAjax.i18n?.corrected || 'Corrected' 
            }; 
            return map[type] || type; 
        }
        function escapeHtml(text) { if (!text) return ''; return String(text).replace(/[&<>"']/g, function(m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]; }); }

        // Init
        updateAdaptiveFilters();
        loadAccountantInvoices(1);
    }
});