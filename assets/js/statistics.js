jQuery(function ($) {
  'use strict';

  // --- GLOBAL VARIABLES ---
  var currentUser = null;
  var usersData = [];
  var invoicesData = [];
  var currentFilters = {
    date_from: '',
    date_to: '',
    payment_method: '', 
    status: 'standard', // Default: Active
    search: ''
  };
  
  // Pagination Configs
  var usersPagination = { current_page: 1, per_page: 20, total_pages: 1 };
  var invoicesPagination = { current_page: 1, per_page: 30, total_pages: 1 };
  var currentSort = { column: 'invoice_count', order: 'desc' };
  
  // Auto Refresh
  var autoRefreshInterval = null;
  var paymentChart = null;

  // Customer Insight Variables
  var custPagination = { current_page: 1, per_page: 20, total_pages: 1 };
  var custFilters = { search: '', dateFrom: '', dateTo: '' };

  // External Balance Variables (NEW)
  var extFilters = { dateFrom: '', dateTo: '' };

  // Fictive Invoices Tab Variables
  var fictiveFilters = { dateFrom: '', dateTo: '', search: '' };

  // Users Section Filters (Independent from global currentFilters)
  var usersSectionFilters = { dateFrom: '', dateTo: '', search: '' };
  var usersSectionSearchTimeout = null;

  // Fictive Users Section Variables
  var fictiveUsersFilters = { dateFrom: '', dateTo: '', search: '' };
  var fictiveUsersData = [];
  var fictiveUsersPagination = { current_page: 1, per_page: 20, total_pages: 1 };
  var fictiveUsersSort = { column: 'invoice_count', order: 'desc' };
  var fictiveUsersSearchTimeout = null;

  // Product Performance Table Variables
  var productPerfFilters = { dateFrom: '', dateTo: '', search: '' };
  var productPerfData = [];
  var productPerfSort = { column: 'total_sold', order: 'desc' };
  var productPerfPagination = { current_page: 1, total_pages: 1 };
  var productSearchTimeout = null;

  // --- INITIALIZATION ---
  $(document).ready(function() {
    initializeFilters();
    
    // Load active tab data
    var activeTab = $('.nav-tab-active').data('tab');
    if (activeTab === 'overview') loadStatistics(true);
    else if (activeTab === 'fictive') loadFictiveStatistics(true);
    else if (activeTab === 'external') loadExternalBalance();
    else if (activeTab === 'customer') loadCustomers();
    else if (activeTab === 'product') loadProductTable();

    startAutoRefresh();
    bindEvents();
  });

  function initializeFilters() {
    var savedSort = localStorage.getItem('cig_stats_sort');
    if (savedSort) { try { currentSort = JSON.parse(savedSort); } catch(e) {} }
    var savedPerPage = localStorage.getItem('cig_stats_per_page');
    if (savedPerPage) {
      usersPagination.per_page = parseInt(savedPerPage, 10);
      $('#cig-users-per-page').val(savedPerPage);
    }
  }

  function bindEvents() {
    // --- TABS NAVIGATION ---
    $(document).on('click', '.nav-tab', function(e) {
        e.preventDefault();
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.cig-tab-content').hide();
        var tab = $(this).data('tab');
        $('#cig-tab-' + tab).show();

        // Load Tab Data on Click
        if (tab === 'customer' && $('#cig-customers-tbody tr.loading-row').length > 0) {
            loadCustomers();
        } else if (tab === 'external') {
            loadExternalBalance();
        } else if (tab === 'product') {
            loadProductTable();
        } else if (tab === 'fictive') {
            loadFictiveStatistics(true);
            // Load Fictive Users data only on first click or if table shows loading
            if ($('#cig-fictive-users-tbody tr.loading-row').length > 0) {
                loadFictiveUsers(true);
            }
        }
    });

    // --- OVERVIEW FILTERS ---
    $(document).on('click', '.cig-quick-filter-btn', handleQuickFilter);
    $(document).on('click', '#cig-apply-date-range', applyCustomDateRange);
    
    $(document).on('change', '#cig-payment-filter', function() {
      currentFilters.payment_method = $(this).val();
      clearSummaryDropdowns();
      hideUserDetail();
      loadSummary(true);
      // Note: Users table has its own independent filters, payment filter only affects summary cards
    });

    $(document).on('click', '#cig-refresh-stats', function() {
      var tab = $('.nav-tab-active').data('tab');
      if (tab === 'overview') {
          clearSummaryDropdowns();
          hideUserDetail();
          loadStatistics(true);
      } else if (tab === 'fictive') {
          clearFictiveDropdown();
          loadFictiveStatistics(true);
          loadFictiveUsers(true);
      } else if (tab === 'customer') {
          loadCustomers();
      } else if (tab === 'external') {
          loadExternalBalance();
      } else if (tab === 'product') {
          loadProductTable();
      }
    });
    
    $(document).on('click', '#cig-export-stats', handleExport);

    // --- OVERVIEW SEARCH BAR ---
    var overviewSearchTimeout;
    $(document).on('input', '#cig-overview-search', function() {
      clearTimeout(overviewSearchTimeout);
      var term = $(this).val();
      overviewSearchTimeout = setTimeout(function() {
        currentFilters.search = term;
        clearSummaryDropdowns();
        loadSummary(true);
      }, 400);
    });

    var searchTimeout;
    $(document).on('input', '#cig-user-search', function() {
      clearTimeout(searchTimeout);
      var term = $(this).val();
      searchTimeout = setTimeout(function() {
        usersSectionFilters.search = term;
        filterUsers();
      }, 300);
    });

    $(document).on('change', '#cig-users-per-page', function() {
      usersPagination.per_page = parseInt($(this).val(), 10);
      usersPagination.current_page = 1;
      localStorage.setItem('cig_stats_per_page', $(this).val());
      displayUsersPage();
    });

    // --- USERS TABLE INTERACTION ---
    $(document).on('click', '#cig-users-table tbody tr.cig-user-row', function(e) {
      if ($(this).hasClass('no-results-row') || $(this).hasClass('loading-row')) return;
      var userId = $(this).data('user-id');
      if (userId) showUserDetail(userId);
    });

    $(document).on('click', '#cig-users-table .sortable', handleSort);

    // --- USERS SECTION INDEPENDENT FILTERS ---
    // Quick Filter Buttons for Users Section
    $(document).on('click', '.cig-users-quick-filter-btn', handleUsersQuickFilter);
    
    // Custom Date Range Apply Button for Users Section
    $(document).on('click', '#cig-users-apply-date-range', function() {
        usersSectionFilters.dateFrom = $('#cig-users-date-from').val();
        usersSectionFilters.dateTo = $('#cig-users-date-to').val();
        if (!usersSectionFilters.dateFrom || !usersSectionFilters.dateTo) {
            alert(cigStats.i18n?.select_both_dates || 'Please select both dates');
            return;
        }
        $('.cig-users-quick-filter-btn').removeClass('active');
        loadUsers(true);
    });

    // --- FICTIVE USERS SECTION EVENTS ---
    // Quick Filter Buttons for Fictive Users Section
    $(document).on('click', '.cig-fictive-users-quick-filter-btn', handleFictiveUsersQuickFilter);
    
    // Custom Date Range Apply Button for Fictive Users Section
    $(document).on('click', '#cig-fictive-users-apply-date-range', function() {
        fictiveUsersFilters.dateFrom = $('#cig-fictive-users-date-from').val();
        fictiveUsersFilters.dateTo = $('#cig-fictive-users-date-to').val();
        if (!fictiveUsersFilters.dateFrom || !fictiveUsersFilters.dateTo) {
            alert(cigStats.i18n?.select_both_dates || 'Please select both dates');
            return;
        }
        $('.cig-fictive-users-quick-filter-btn').removeClass('active');
        loadFictiveUsers(true);
    });
    
    // Search Input for Fictive Users Section
    $(document).on('input', '#cig-fictive-user-search', function() {
        clearTimeout(fictiveUsersSearchTimeout);
        var term = $(this).val();
        fictiveUsersSearchTimeout = setTimeout(function() {
            fictiveUsersFilters.search = term;
            filterFictiveUsers();
        }, 300);
    });
    
    // Per Page Selector for Fictive Users Section
    $(document).on('change', '#cig-fictive-users-per-page', function() {
        fictiveUsersPagination.per_page = parseInt($(this).val(), 10);
        fictiveUsersPagination.current_page = 1;
        displayFictiveUsersPage();
    });
    
    // Sortable Headers for Fictive Users Table
    $(document).on('click', '#cig-fictive-users-table .sortable', handleFictiveUsersSort);

    // --- USER DETAIL VIEW ---
    $(document).on('click', '#cig-back-to-users', function() {
      hideUserDetail();
    });

    var invoiceSearchTimeout;
    $(document).on('input', '#cig-invoice-search', function() {
      clearTimeout(invoiceSearchTimeout);
      var term = $(this).val();
      invoiceSearchTimeout = setTimeout(function() {
        currentFilters.search = term;
        filterUserInvoices();
      }, 300);
    });

    $(document).on('change', '#cig-user-payment-filter', function() {
      currentFilters.payment_method = $(this).val();
      if (currentUser) loadUserInvoices(currentUser.user_id);
    });

    $(document).on('change', '#cig-invoices-per-page', function() {
      invoicesPagination.per_page = parseInt($(this).val(), 10);
      invoicesPagination.current_page = 1;
      displayInvoicesPage();
    });

    // --- PAGINATION (Shared Logic) ---
    $(document).on('click', '.cig-page-btn', function() {
      if ($(this).is(':disabled') || $(this).hasClass('active')) return;
      var page = parseInt($(this).data('page'), 10);
      
      if ($(this).closest('#cig-users-pagination').length) {
        usersPagination.current_page = page;
        displayUsersPage();
      } else if ($(this).closest('#cig-invoices-pagination').length) {
        invoicesPagination.current_page = page;
        displayInvoicesPage();
      } else if ($(this).closest('#cig-fictive-users-pagination').length) {
        fictiveUsersPagination.current_page = page;
        displayFictiveUsersPage();
      } else if ($(this).hasClass('cig-cust-page-btn')) { // Customer Pagination
        custPagination.current_page = page;
        loadCustomers();
      }
    });

    // --- SUMMARY CARDS CLICK ---
    $(document).on('click', '.cig-stat-card[data-dropdown="invoices"], .cig-stat-card[data-dropdown="outstanding"]', function() {
        var dropdownType = $(this).data('dropdown');
        var method = $(this).data('method'); 
        var cardTitle = $(this).find('.cig-stat-label').text();
        
        currentFilters.payment_method = method; 
        
        if (dropdownType === 'outstanding') {
            toggleOutstandingDropdown(cardTitle);
        } else {
            toggleInvoicesDropdown(method, cardTitle);
        }
    });

    $(document).on('click', '#cig-card-products-sold', function() {
      toggleProductsDropdown('sold');
    });
    $(document).on('click', '#cig-card-products-reserved', function() {
      toggleProductsDropdown('reserved');
    });

    $(document).on('click', '.cig-summary-close', function() {
      var target = $(this).data('target');
      $(target).slideUp(150);
    });

    // --- CUSTOMER INSIGHT EVENTS ---
    var custSearchTimeout;
    $(document).on('input', '#cig-customer-search', function() {
        clearTimeout(custSearchTimeout);
        var val = $(this).val();
        custSearchTimeout = setTimeout(function() {
            custFilters.search = val;
            custPagination.current_page = 1;
            loadCustomers();
        }, 400);
    });

    $(document).on('click', '#cig-cust-apply-date', function() {
        custFilters.dateFrom = $('#cig-cust-date-from').val();
        custFilters.dateTo = $('#cig-cust-date-to').val();
        custPagination.current_page = 1;
        loadCustomers();
    });

    // Customer row click - drill-down to invoices
    $(document).on('click', '.cig-customer-row', function(e) {
        e.preventDefault();
        var custId = $(this).data('customer-id');
        if (custId) {
            showCustomerDetail(custId);
        }
    });

    // Keep old handler for backward compatibility
    $(document).on('click', '.cig-cust-tax-link', function(e) {
        e.preventDefault();
        var custId = $(this).data('id');
        showCustomerDetail(custId);
    });

    $(document).on('click', '#cig-back-to-customers', function() {
        $('#cig-customer-detail-panel').slideUp();
        $('#cig-customer-list-panel').slideDown();
    });

    // --- EXTERNAL BALANCE EVENTS (NEW) ---
    $(document).on('click', '#cig-ext-apply-date', function() {
        extFilters.dateFrom = $('#cig-ext-date-from').val();
        extFilters.dateTo = $('#cig-ext-date-to').val();
        loadExternalBalance();
    });

    $(document).on('click', '#cig-btn-add-deposit', function() {
        $('#cig-deposit-modal').fadeIn(200);
    });

    $(document).on('click', '#cig-close-deposit-modal', function() {
        $('#cig-deposit-modal').fadeOut(200);
    });

    $(document).on('click', '#cig-submit-deposit', function() {
        submitDeposit();
    });

    $(document).on('click', '.cig-btn-delete-deposit', function() {
        if(!confirm(cigStats.i18n?.confirm_delete_record || 'Are you sure you want to delete this record?')) return;
        deleteDeposit($(this).data('id'));
    });

    // --- FICTIVE INVOICES TAB EVENTS ---
    // Quick Filter Buttons
    $(document).on('click', '.cig-fictive-quick-filter-btn', handleFictiveQuickFilter);
    
    // Date Range Apply Button
    $(document).on('click', '#cig-fictive-apply-date-range', function() {
        fictiveFilters.dateFrom = $('#cig-fictive-date-from').val();
        fictiveFilters.dateTo = $('#cig-fictive-date-to').val();
        if (!fictiveFilters.dateFrom || !fictiveFilters.dateTo) {
            alert(cigStats.i18n?.select_both_dates || 'Please select both dates');
            return;
        }
        $('.cig-fictive-quick-filter-btn').removeClass('active');
        clearFictiveDropdown();
        loadFictiveStatistics(true);
    });
    
    // Search Input with Debounce
    var fictiveSearchTimeout;
    $(document).on('input', '#cig-fictive-search', function() {
        clearTimeout(fictiveSearchTimeout);
        var term = $(this).val();
        fictiveSearchTimeout = setTimeout(function() {
            fictiveFilters.search = term;
            clearFictiveDropdown();
            loadFictiveStatistics(true);
        }, 400);
    });
    
    // Fictive Summary Cards Click - Drill-Down
    $(document).on('click', '.cig-fictive-card', function() {
        toggleFictiveInvoicesDropdown();
    });
    
    // Close Button for Fictive Dropdown
    $(document).on('click', '.cig-summary-close[data-target="#cig-summary-fictive-invoices"]', function() {
        $('#cig-summary-fictive-invoices').slideUp(150);
    });

    // --- PRODUCT PERFORMANCE TABLE EVENTS ---
    // Date Apply Button
    $(document).on('click', '#cig-pp-apply-date', function() {
        productPerfFilters.dateFrom = $('#cig-pp-date-from').val();
        productPerfFilters.dateTo = $('#cig-pp-date-to').val();
        productPerfPagination.current_page = 1;
        loadProductTable(1);
    });

    // Live Search with Debounce (500ms)
    $(document).on('input', '#cig-pp-search', function() {
        clearTimeout(productSearchTimeout);
        var val = $(this).val();
        productSearchTimeout = setTimeout(function() {
            productPerfFilters.search = val;
            productPerfPagination.current_page = 1;
            loadProductTable(1);
        }, 500);
    });

    // Sortable Headers for Product Performance Table
    $(document).on('click', '#cig-product-perf-table .sortable', function() {
        var column = $(this).data('sort');
        if (productPerfSort.column === column) {
            productPerfSort.order = productPerfSort.order === 'asc' ? 'desc' : 'asc';
        } else {
            productPerfSort.column = column;
            productPerfSort.order = 'desc';
        }
        sortProductTable();
        updateProductSortArrows();
    });

    // Product Performance Pagination
    $(document).on('click', '#cig-product-pagination .cig-page-btn', function() {
        if ($(this).is(':disabled') || $(this).hasClass('active')) return;
        var page = parseInt($(this).data('page'), 10);
        productPerfPagination.current_page = page;
        loadProductTable(page);
    });

    // --- CLICK-TO-EDIT DATE FUNCTIONALITY ---
    // Click on edit icon to show the date input
    $(document).on('click', '.cig-btn-edit-date', function(e) {
        e.stopPropagation();
        var $cell = $(this).closest('td');
        $cell.find('.cig-view-date').hide();
        $cell.find('.cig-edit-date').show();
        $cell.find('.cig-invoice-date-input').focus();
    });

    // On change or blur of date input, save and switch back to view mode
    $(document).on('change', '.cig-invoice-date-input', function() {
        var $input = $(this);
        var invoiceId = $input.data('invoice-id');
        var newDate = $input.val();
        var $cell = $input.closest('td');
        var $viewMode = $cell.find('.cig-view-date');
        var $editMode = $cell.find('.cig-edit-date');

        if (!newDate) {
            // If empty, revert to view mode without saving
            $editMode.hide();
            $viewMode.show();
            return;
        }

        // Show loading state
        $input.prop('disabled', true);

        $.ajax({
            url: cigStats.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'cig_update_invoice_date_stat',
                nonce: cigStats.nonce,
                invoice_id: invoiceId,
                new_date: newDate
            },
            success: function(res) {
                if (res && res.success) {
                    // Update the display text with formatted date
                    var displayDate = newDate.replace('T', ' ').substring(0, 16);
                    $viewMode.find('.date-text').text(displayDate);
                    
                    // Switch back to view mode
                    $editMode.hide();
                    $viewMode.show();
                } else {
                    alert(res.data?.message || cigStats.i18n?.error_updating_date || 'Error updating date');
                }
            },
            error: function() {
                alert(cigStats.i18n?.error_updating_date || 'Error updating date');
            },
            complete: function() {
                $input.prop('disabled', false);
            }
        });
    });

    // On blur without change, just hide the input
    $(document).on('blur', '.cig-invoice-date-input', function() {
        var $input = $(this);
        // Small delay to allow change event to fire first
        setTimeout(function() {
            var $cell = $input.closest('td');
            if ($cell.find('.cig-edit-date').is(':visible')) {
                $cell.find('.cig-edit-date').hide();
                $cell.find('.cig-view-date').show();
            }
        }, 150);
    });
  }

  // --- EXTERNAL BALANCE LOGIC ---
  function loadExternalBalance() {
      // Show loading state
      $('#cig-ext-accumulated').text('...');
      $('#cig-ext-deposited').text('...');
      $('#cig-ext-balance').text('...');
      $('#cig-ext-history-tbody').html('<tr class="loading-row"><td colspan="4"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading || 'Loading...') + '</p></div></td></tr>');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_external_balance',
              nonce: cigStats.nonce,
              start_date: extFilters.dateFrom,
              end_date: extFilters.dateTo
          },
          success: function(res) {
              if (res.success && res.data) {
                  // Update Cards
                  $('#cig-ext-accumulated').html(formatCurrency(res.data.cards.accumulated));
                  $('#cig-ext-deposited').html(formatCurrency(res.data.cards.deposited));
                  
                  var bal = parseFloat(res.data.cards.balance);
                  var balHtml = formatCurrency(bal);
                  if(bal < -0.01) {
                      // Negative means Debt/Due
                      $('#cig-ext-balance').html('<span style="color:#dc3545;">' + balHtml + '</span>');
                  } else {
                      $('#cig-ext-balance').html('<span style="color:#28a745;">' + balHtml + '</span>');
                  }

                  // Update Table
                  renderDepositHistory(res.data.history);
              }
          },
          error: function() {
              alert(cigStats.i18n?.error_loading_external || 'Error loading external balance data.');
          }
      });
  }

  function renderDepositHistory(history) {
      if (!history || !history.length) {
          $('#cig-ext-history-tbody').html('<tr><td colspan="4" style="text-align:center; padding:20px; color:#999;">' + (cigStats.i18n?.no_deposit_history || 'No deposit history found.') + '</td></tr>');
          return;
      }

      var html = '';
      history.forEach(function(row) {
          html += '<tr>';
          html += '<td>' + row.date + '</td>';
          html += '<td>' + escapeHtml(row.comment || '—') + '</td>';
          html += '<td style="text-align:right; font-weight:bold; color:#28a745;">' + formatCurrency(row.amount) + '</td>';
          html += '<td style="text-align:center;"><button type="button" class="button cig-btn-delete-deposit" data-id="' + row.id + '" style="color:#dc3545; border:none; background:transparent;"><span class="dashicons dashicons-trash"></span></button></td>';
          html += '</tr>';
      });
      $('#cig-ext-history-tbody').html(html);
  }

  function submitDeposit() {
      var amount = $('#cig-dep-amount').val();
      var date = $('#cig-dep-date').val();
      var note = $('#cig-dep-note').val();

      if (!amount || parseFloat(amount) <= 0) {
          alert(cigStats.i18n?.enter_valid_amount || 'Please enter a valid amount.');
          return;
      }
      if (!date) {
          alert(cigStats.i18n?.select_date || 'Please select a date.');
          return;
      }

      var $btn = $('#cig-submit-deposit');
      $btn.prop('disabled', true).text(cigStats.i18n?.saving || 'Saving...');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_add_deposit',
              nonce: cigStats.nonce,
              amount: amount,
              date: date,
              note: note
          },
          success: function(res) {
              $btn.prop('disabled', false).text(cigStats.i18n?.confirm || 'Confirm');
              if (res.success) {
                  $('#cig-deposit-modal').fadeOut();
                  $('#cig-dep-amount').val('');
                  $('#cig-dep-note').val('');
                  loadExternalBalance(); // Refresh
              } else {
                  alert(res.data.message || cigStats.i18n?.error_saving_deposit || 'Error saving deposit.');
              }
          },
          error: function() {
              $btn.prop('disabled', false).text(cigStats.i18n?.confirm || 'Confirm');
              alert(cigStats.i18n?.server_error || 'Server error.');
          }
      });
  }

  function deleteDeposit(id) {
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_delete_deposit',
              nonce: cigStats.nonce,
              id: id
          },
          success: function(res) {
              if (res.success) {
                  loadExternalBalance();
              } else {
                  alert(res.data.message);
              }
          }
      });
  }

  // --- FICTIVE INVOICES TAB LOGIC ---

  /**
   * Handle Quick Filter Button Click for Fictive Tab
   */
  function handleFictiveQuickFilter() {
      $('.cig-fictive-quick-filter-btn').removeClass('active');
      $(this).addClass('active');
      var filter = $(this).data('filter');
      var dateRange = calculateDateRange(filter);
      $('#cig-fictive-date-from').val(dateRange.from);
      $('#cig-fictive-date-to').val(dateRange.to);
      fictiveFilters.dateFrom = dateRange.from;
      fictiveFilters.dateTo = dateRange.to;
      clearFictiveDropdown();
      loadFictiveStatistics(true);
  }

  /**
   * Load Fictive Statistics Summary
   * Calls cig_get_statistics_summary with status='fictive' and payment_method=''
   */
  function loadFictiveStatistics(force) {
      if (force) {
          $('#stat-fictive-invoices').html('<span class="loading-stat">...</span>');
          $('#stat-fictive-amount').html('<span class="loading-stat">...</span>');
      }
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_statistics_summary',
              nonce: cigStats.nonce,
              date_from: fictiveFilters.dateFrom,
              date_to: fictiveFilters.dateTo,
              payment_method: '',  // Empty - Fictive invoices don't have payments
              status: 'fictive',   // ALWAYS fictive for this tab
              search: fictiveFilters.search
          },
          success: function(res) {
              if (res && res.success && res.data) {
                  $('#stat-fictive-invoices').html(formatNumber(res.data.total_invoices));
                  $('#stat-fictive-amount').html(formatCurrency(res.data.total_revenue));
              } else {
                  $('#stat-fictive-invoices').text('0');
                  $('#stat-fictive-amount').text('0.00 ₾');
              }
          },
          error: function() {
              $('#stat-fictive-invoices').text('0');
              $('#stat-fictive-amount').text('0.00 ₾');
          }
      });
  }

  /**
   * Clear Fictive Dropdown
   */
  function clearFictiveDropdown() {
      $('#cig-summary-fictive-invoices').hide();
  }

  /**
   * Toggle Fictive Invoices Drill-Down Dropdown
   */
  function toggleFictiveInvoicesDropdown() {
      var $panel = $('#cig-summary-fictive-invoices');
      if ($panel.is(':visible')) {
          $panel.slideUp(150);
          return;
      }
      
      $('#cig-summary-fictive-invoices-tbody').html('<tr class="loading-row"><td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading || 'Loading...') + '</p></div></td></tr>');
      $panel.slideDown(150);
      
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_invoices_by_filters',
              nonce: cigStats.nonce,
              date_from: fictiveFilters.dateFrom,
              date_to: fictiveFilters.dateTo,
              payment_method: '',   // Empty - Fictive invoices don't have payments
              status: 'fictive',    // ALWAYS fictive for this tab
              search: fictiveFilters.search
          },
          success: function(res) {
              if (res && res.success && res.data && res.data.invoices && res.data.invoices.length) {
                  var html = '';
                  res.data.invoices.forEach(function(inv) {
                      html += '<tr>';
                      html += '<td><strong>' + escapeHtml(inv.invoice_number || '') + '</strong></td>';
                      html += '<td>' + escapeHtml(inv.customer) + '</td>';
                      html += '<td>' + formatTotalWithDiscount(inv.total || 0, inv.original_total) + '</td>';
                      html += '<td>' + formatDateTime(inv.date) + '</td>';
                      html += '<td>' + escapeHtml(inv.author || '') + '</td>';
                      html += '<td><a class="cig-btn-sm cig-btn-view" href="' + inv.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + inv.edit_url + '" target="_blank">რედაქტირება</a></td>';
                      html += '</tr>';
                  });
                  $('#cig-summary-fictive-invoices-tbody').html(html);
              } else {
                  $('#cig-summary-fictive-invoices-tbody').html('<tr class="no-results-row"><td colspan="6">' + (cigStats.i18n?.no_fictive_invoices || 'No fictive invoices found') + '</td></tr>');
              }
          },
          error: function() {
              $('#cig-summary-fictive-invoices-tbody').html('<tr class="no-results-row"><td colspan="6" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_fictive || 'Error loading invoices') + '</td></tr>');
          }
      });
  }

  // --- PRODUCT PERFORMANCE TABLE LOGIC ---

  /**
   * Load Product Performance Table
   * Fetches data from cig_get_product_performance_table AJAX action
   * @param {number} page - Page number (default: 1)
   */
  function loadProductTable(page) {
      page = page || 1;
      productPerfPagination.current_page = page;

      // Show loading state
      $('#cig-product-perf-tbody').html('<tr class="loading-row"><td colspan="8"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_products || 'Loading product performance...') + '</p></div></td></tr>');

      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_product_performance_table',
              nonce: cigStats.nonce,
              date_from: productPerfFilters.dateFrom,
              date_to: productPerfFilters.dateTo,
              search: productPerfFilters.search,
              page: page
          },
          success: function(res) {
              if (res.success && res.data && res.data.products) {
                  productPerfData = res.data.products;
                  if (res.data.pagination) {
                      productPerfPagination.current_page = res.data.pagination.current_page;
                      productPerfPagination.total_pages = res.data.pagination.total_pages;
                  }
                  renderProductTable(productPerfData);
                  renderProductPagination();
                  updateProductSortArrows();
                  // Update sold summary cards
                  if (res.data.totals) {
                      $('#cig-pp-total-units').text(formatNumber(res.data.totals.total_units_sold));
                      $('#cig-pp-unique-products').text(formatNumber(res.data.totals.unique_products));
                      $('#cig-pp-total-income').text(formatNumber(res.data.totals.total_income) + ' ₾');
                      $('#cig-product-sold-summary').show();
                  }
              } else {
                  $('#cig-product-perf-tbody').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:#999;">' + (cigStats.i18n?.no_products_found || 'No products found') + '</td></tr>');
                  $('#cig-product-pagination').html('');
                  $('#cig-product-sold-summary').hide();
              }
          },
          error: function() {
              $('#cig-product-perf-tbody').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:#dc3545;">' + (cigStats.i18n?.error_loading_products || 'Error loading products') + '</td></tr>');
              $('#cig-product-pagination').html('');
              $('#cig-product-sold-summary').hide();
          }
      });
  }

  /**
   * Sort Product Table client-side
   */
  function sortProductTable() {
      if (!productPerfData || !productPerfData.length) return;

      productPerfData.sort(function(a, b) {
          var aVal = a[productPerfSort.column];
          var bVal = b[productPerfSort.column];
          
          // Handle null values
          if (aVal === null) aVal = 0;
          if (bVal === null) bVal = 0;
          
          if (productPerfSort.order === 'asc') {
              return aVal > bVal ? 1 : (aVal < bVal ? -1 : 0);
          }
          return aVal < bVal ? 1 : (aVal > bVal ? -1 : 0);
      });

      renderProductTable(productPerfData);
  }

  /**
   * Update sort arrows in Product Performance table
   */
  function updateProductSortArrows() {
      $('#cig-product-perf-table .sortable').removeClass('active-sort').removeAttr('data-order');
      var $active = $('#cig-product-perf-table .sortable[data-sort="' + productPerfSort.column + '"]');
      $active.addClass('active-sort').attr('data-order', productPerfSort.order);
  }

  /**
   * Render Product Performance Table rows
   */
  function renderProductTable(products) {
      if (!products || !products.length) {
          $('#cig-product-perf-tbody').html('<tr><td colspan="8" style="text-align:center; padding:20px; color:#999;">' + (cigStats.i18n?.no_products_found || 'No products found') + '</td></tr>');
          return;
      }

      var html = '';
      var placeholderImg = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"%3E%3Crect fill="%23f0f0f0" width="48" height="48"/%3E%3Ctext x="24" y="28" font-family="sans-serif" font-size="8" fill="%23999" text-anchor="middle"%3ENo Image%3C/text%3E%3C/svg%3E';

      products.forEach(function(product) {
          var imgSrc = product.image || placeholderImg;
          var stockDisplay = product.stock !== null ? formatNumber(product.stock) : '—';
          var stockStyle = '';
          if (product.stock !== null && product.stock <= 0) {
              stockStyle = 'color:#dc3545;font-weight:bold;';
          } else if (product.stock !== null && product.stock <= 5) {
              stockStyle = 'color:#ffc107;font-weight:bold;';
          }

          html += '<tr>';
          // Photo
          html += '<td><img src="' + imgSrc + '" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #eee;border-radius:4px;background:#fff;"></td>';
          // Product (Name + SKU/Code)
          html += '<td><div><strong>' + escapeHtml(product.name || '—') + '</strong><br><span style="color:#888;font-size:11px;">Code: ' + escapeHtml(product.sku || '—') + '</span></div></td>';
          // Price
          html += '<td>' + formatCurrency(product.price) + '</td>';
          // Current Stock
          html += '<td style="' + stockStyle + '">' + stockDisplay + '</td>';
          // Reserved (In period)
          html += '<td><span class="cig-badge badge-reserved">' + formatNumber(product.total_reserved) + '</span></td>';
          // Fictive (In period - NEW Column)
          html += '<td><span class="cig-badge badge-canceled">' + formatNumber(product.total_fictive) + '</span></td>';
          // Total Sold (In period)
          html += '<td><span class="cig-badge badge-sold">' + formatNumber(product.total_sold) + '</span></td>';
          // Total Revenue (In period)
          html += '<td><strong style="color:#28a745;">' + formatCurrency(product.total_revenue) + '</strong></td>';
          html += '</tr>';
      });
      $('#cig-product-perf-tbody').html(html);
  }

  /**
   * Render Product Performance Pagination
   */
  function renderProductPagination() {
      var $container = $('#cig-product-pagination');
      var cp = productPerfPagination.current_page;
      var tp = productPerfPagination.total_pages;

      if (tp <= 1) {
          $container.html('');
          return;
      }

      var html = '<button class="cig-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>« Prev</button>';
      
      var startPage = Math.max(1, cp - 2);
      var endPage = Math.min(tp, cp + 2);
      
      if (startPage > 1) {
          html += '<button class="cig-page-btn" data-page="1">1</button>';
          if (startPage > 2) html += '<span style="padding:0 5px;color:#999;">...</span>';
      }
      
      for (var i = startPage; i <= endPage; i++) {
          html += '<button class="cig-page-btn ' + (i === cp ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>';
      }
      
      if (endPage < tp) {
          if (endPage < tp - 1) html += '<span style="padding:0 5px;color:#999;">...</span>';
          html += '<button class="cig-page-btn" data-page="' + tp + '">' + tp + '</button>';
      }
      
      html += '<button class="cig-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>Next »</button>';
      
      $container.html(html);
  }

  // --- OVERVIEW LOGIC ---

  function handleQuickFilter() {
    // Skip if this is a Fictive tab quick filter button (has its own handler)
    if ($(this).hasClass('cig-fictive-quick-filter-btn')) {
      return;
    }
    // Skip if this is a Users section quick filter button (has its own handler)
    if ($(this).hasClass('cig-users-quick-filter-btn')) {
      return;
    }
    // Skip if this is a Fictive Users section quick filter button (has its own handler)
    if ($(this).hasClass('cig-fictive-users-quick-filter-btn')) {
      return;
    }
    $('.cig-quick-filter-btn:not(.cig-fictive-quick-filter-btn):not(.cig-users-quick-filter-btn):not(.cig-fictive-users-quick-filter-btn)').removeClass('active');
    $(this).addClass('active');
    var filter = $(this).data('filter');
    var dateRange = calculateDateRange(filter);
    $('#cig-date-from').val(dateRange.from); $('#cig-date-to').val(dateRange.to);
    currentFilters.date_from = dateRange.from; currentFilters.date_to = dateRange.to;
    clearSummaryDropdowns(); hideUserDetail(); loadSummary(true);
    // Note: Users table now has its own independent filters, so we don't reload users here
  }

  /**
   * Handle Quick Filter Button Click for Users Section (General Overview)
   */
  function handleUsersQuickFilter() {
    $('.cig-users-quick-filter-btn').removeClass('active');
    $(this).addClass('active');
    var filter = $(this).data('filter');
    var dateRange = calculateDateRange(filter);
    $('#cig-users-date-from').val(dateRange.from);
    $('#cig-users-date-to').val(dateRange.to);
    usersSectionFilters.dateFrom = dateRange.from;
    usersSectionFilters.dateTo = dateRange.to;
    loadUsers(true);
  }

  /**
   * Handle Quick Filter Button Click for Fictive Users Section
   */
  function handleFictiveUsersQuickFilter() {
    $('.cig-fictive-users-quick-filter-btn').removeClass('active');
    $(this).addClass('active');
    var filter = $(this).data('filter');
    var dateRange = calculateDateRange(filter);
    $('#cig-fictive-users-date-from').val(dateRange.from);
    $('#cig-fictive-users-date-to').val(dateRange.to);
    fictiveUsersFilters.dateFrom = dateRange.from;
    fictiveUsersFilters.dateTo = dateRange.to;
    loadFictiveUsers(true);
  }

  function applyCustomDateRange() {
    var from = $('#cig-date-from').val();
    var to = $('#cig-date-to').val();
    if (!from || !to) { alert(cigStats.i18n?.select_both_dates || 'Please select both dates'); return; }
    $('.cig-quick-filter-btn:not(.cig-users-quick-filter-btn):not(.cig-fictive-users-quick-filter-btn):not(.cig-fictive-quick-filter-btn)').removeClass('active');
    currentFilters.date_from = from; currentFilters.date_to = to;
    clearSummaryDropdowns(); hideUserDetail(); loadSummary(true);
    // Note: Users table now has its own independent filters, so we don't reload users here
  }

  function loadStatistics(force) { loadSummary(force); loadUsers(force); }

  function loadSummary(force) {
    if (force) {
      $('.cig-stat-value').html('<span class="loading-stat">...</span>');
      $('#cig-payment-chart-empty').hide();
    }
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { 
          action: 'cig_get_statistics_summary', 
          nonce: cigStats.nonce, 
          date_from: currentFilters.date_from, 
          date_to: currentFilters.date_to, 
          payment_method: $('#cig-payment-filter').val(), // Global filter if any
          status: 'standard',  // General Overview tab ALWAYS uses 'standard' (Active invoices only)
          search: currentFilters.search
      },
      success: function(res) {
        if (res && res.success && res.data) { 
            updateSummaryCards(res.data); 
            updatePaymentChart(res.data);
        } else { 
            showSummaryEmpty(); 
        }
      },
      error: function(){ showSummaryEmpty(); }
    });
  }

  function showSummaryEmpty() {
    $('.cig-stat-value').text('0');
    $('#cig-payment-chart-empty').show();
    if (paymentChart) { paymentChart.destroy(); paymentChart = null; }
  }

  function updateSummaryCards(data) {
    $('#stat-total-invoices').html(formatNumber(data.total_invoices));
    $('#stat-total-revenue').html(formatCurrency(data.total_revenue));
    $('#stat-total-paid').html(formatCurrency(data.total_paid));
    $('#stat-total-outstanding').html(formatCurrency(data.total_outstanding));
    $('#stat-total-reserved-invoices').html(formatNumber(data.total_reserved_invoices) + ' ინვოისი');
    $('#stat-total-reserved-invoices').html(formatNumber(data.total_reserved_invoices));
    
    // Payment Methods
    $('#stat-total-cash').html(formatCurrency(data.total_cash));
    $('#stat-total-company_transfer').html(formatCurrency(data.total_company_transfer));
    $('#stat-total-credit').html(formatCurrency(data.total_credit));
    $('#stat-total-consignment').html(formatCurrency(data.total_consignment));
    $('#stat-total-other').html(formatCurrency(data.total_other));
    
    // Legacy products stats
    $('#stat-products-sold').html(formatNumber(data.total_sold));
    $('#stat-products-reserved').html(formatNumber(data.total_reserved));
  }

  function updatePaymentChart(data) {
    var $empty = $('#cig-payment-chart-empty');
    var labels = ['ჩარიცხვა', 'ქეში', 'განვადება', 'კონსიგნაცია', 'სხვა'];
    var values = [
        data.total_company_transfer,
        data.total_cash,
        data.total_credit,
        data.total_consignment,
        data.total_other
    ];
    
    var colors = [
        cigStats.colors.info, 
        cigStats.colors.success, 
        '#6c757d', 
        cigStats.colors.warning, 
        '#343a40'
    ];

    var totalVal = values.reduce((a, b) => a + b, 0);
    
    var ctx = document.getElementById('cig-payment-chart');
    if (!ctx) return;
    if (paymentChart) { paymentChart.destroy(); paymentChart = null; }
    
    if (totalVal <= 0.01) { $empty.show(); return; }
    $empty.hide();
    
    paymentChart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { padding: 15, font: { size: 12 } } }, tooltip: { callbacks: { label: function(c){ return (c.label||'') + ': ' + formatCurrency(c.parsed); } } } } }
    });
  }

  function loadUsers(force) {
    if (force) { $('#cig-users-tbody').html('<tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_users || 'Loading users...') + '</p></div></td></tr>'); }
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_users_statistics', nonce: cigStats.nonce, date_from: usersSectionFilters.dateFrom, date_to: usersSectionFilters.dateTo, search: usersSectionFilters.search, sort_by: currentSort.column, sort_order: currentSort.order, status: 'standard' },
      success: function(res) { if (res && res.success && res.data) { usersData = res.data.users; filterUsers(); } else { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7">' + (cigStats.i18n?.no_users_found || 'No users found') + '</td></tr>'); } },
      error: function() { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_users || 'Error loading users') + '</td></tr>'); }
    });
  }

  function filterUsers() {
    var filtered = usersData;
    if (usersSectionFilters.search) { var term = usersSectionFilters.search.toLowerCase(); filtered = usersData.filter(function(u){ return u.user_name.toLowerCase().includes(term) || u.user_email.toLowerCase().includes(term); }); }
    usersPagination.current_page = 1;
    usersPagination.total_pages = Math.ceil(filtered.length / usersPagination.per_page);
    displayUsersPage(filtered);
  }

  function displayUsersPage(filtered) {
    filtered = filtered || usersData;
    usersPagination.total_pages = Math.ceil(filtered.length / usersPagination.per_page);
    var start = (usersPagination.current_page - 1) * usersPagination.per_page;
    var end = start + usersPagination.per_page;
    var pageData = filtered.slice(start, end);
    if (!pageData.length) { $('#cig-users-tbody').html('<tr class="no-results-row"><td colspan="7">' + (cigStats.i18n?.no_users_found || 'No users found') + '</td></tr>'); $('#cig-users-pagination').html(''); return; }
    var html = '';
    pageData.forEach(function(user){
      html += '<tr class="cig-user-row" data-user-id="' + user.user_id + '">';
      html += '<td><div class="user-cell"><img src="' + user.user_avatar + '" alt="" class="user-avatar"><div class="user-info"><div class="user-name">' + escapeHtml(user.user_name) + '</div><div class="user-email">' + escapeHtml(user.user_email) + '</div></div></div></td>';
      html += '<td><strong>' + user.invoice_count + '</strong></td>';
      html += '<td><span class="cig-badge badge-sold">' + formatNumber(user.total_sold) + '</span></td>';
      html += '<td><span class="cig-badge badge-reserved">' + formatNumber(user.total_reserved) + '</span></td>';
      html += '<td><span class="cig-badge badge-canceled">' + formatNumber(user.total_canceled) + '</span></td>';
      html += '<td><strong>' + formatCurrency(user.total_revenue) + '</strong></td>';
      html += '<td>' + formatDateTime(user.last_invoice_date) + '</td>';
      html += '</tr>';
    });
    $('#cig-users-tbody').html(html); renderPagination('users'); updateSortArrows();
  }

  function handleSort() {
    var column = $(this).data('sort');
    if (currentSort.column === column) currentSort.order = currentSort.order === 'asc' ? 'desc' : 'asc'; else { currentSort.column = column; currentSort.order = 'desc'; }
    $(this).data('order', currentSort.order); localStorage.setItem('cig_stats_sort', JSON.stringify(currentSort)); sortUsers();
  }
  function sortUsers() { usersData.sort(function(a,b){ var aVal = a[currentSort.column]; var bVal = b[currentSort.column]; if (currentSort.order === 'asc') return aVal > bVal ? 1 : -1; return aVal < bVal ? 1 : -1; }); displayUsersPage(); }
  function updateSortArrows() { $('#cig-users-table .sortable').removeClass('active-sort').removeAttr('data-order'); var $active = $('#cig-users-table .sortable[data-sort="' + currentSort.column + '"]'); $active.addClass('active-sort').attr('data-order', currentSort.order); }

  // --- FICTIVE USERS SECTION LOGIC ---
  
  /**
   * Load Fictive Users Statistics
   * Calls cig_get_users_statistics with status='fictive'
   */
  function loadFictiveUsers(force) {
    if (force) {
      $('#cig-fictive-users-tbody').html('<tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_users || 'Loading users...') + '</p></div></td></tr>');
    }
    $.ajax({
      url: cigStats.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'cig_get_users_statistics',
        nonce: cigStats.nonce,
        date_from: fictiveUsersFilters.dateFrom,
        date_to: fictiveUsersFilters.dateTo,
        search: fictiveUsersFilters.search,
        sort_by: fictiveUsersSort.column,
        sort_order: fictiveUsersSort.order,
        status: 'fictive' // CRUCIAL: Always 'fictive' for this section
      },
      success: function(res) {
        if (res && res.success && res.data) {
          fictiveUsersData = res.data.users;
          filterFictiveUsers();
        } else {
          $('#cig-fictive-users-tbody').html('<tr class="no-results-row"><td colspan="7">' + (cigStats.i18n?.no_users_found || 'No users found') + '</td></tr>');
        }
      },
      error: function() {
        $('#cig-fictive-users-tbody').html('<tr class="no-results-row"><td colspan="7" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_users || 'Error loading users') + '</td></tr>');
      }
    });
  }

  /**
   * Filter Fictive Users client-side by search term
   */
  function filterFictiveUsers() {
    var filtered = fictiveUsersData;
    if (fictiveUsersFilters.search) {
      var term = fictiveUsersFilters.search.toLowerCase();
      filtered = fictiveUsersData.filter(function(u) {
        return u.user_name.toLowerCase().includes(term) || u.user_email.toLowerCase().includes(term);
      });
    }
    fictiveUsersPagination.current_page = 1;
    fictiveUsersPagination.total_pages = Math.ceil(filtered.length / fictiveUsersPagination.per_page);
    displayFictiveUsersPage(filtered);
  }

  /**
   * Display Fictive Users Page
   */
  function displayFictiveUsersPage(filtered) {
    filtered = filtered || fictiveUsersData;
    fictiveUsersPagination.total_pages = Math.ceil(filtered.length / fictiveUsersPagination.per_page);
    var start = (fictiveUsersPagination.current_page - 1) * fictiveUsersPagination.per_page;
    var end = start + fictiveUsersPagination.per_page;
    var pageData = filtered.slice(start, end);
    
    if (!pageData.length) {
      $('#cig-fictive-users-tbody').html('<tr class="no-results-row"><td colspan="7">' + (cigStats.i18n?.no_users_found || 'No users found') + '</td></tr>');
      $('#cig-fictive-users-pagination').html('');
      return;
    }
    
    var html = '';
    pageData.forEach(function(user) {
      html += '<tr class="cig-fictive-user-row" data-user-id="' + user.user_id + '">';
      html += '<td><div class="user-cell"><img src="' + user.user_avatar + '" alt="" class="user-avatar"><div class="user-info"><div class="user-name">' + escapeHtml(user.user_name) + '</div><div class="user-email">' + escapeHtml(user.user_email) + '</div></div></div></td>';
      html += '<td><strong>' + user.invoice_count + '</strong></td>';
      html += '<td><span class="cig-badge badge-sold">' + formatNumber(user.total_sold) + '</span></td>';
      html += '<td><span class="cig-badge badge-reserved">' + formatNumber(user.total_reserved) + '</span></td>';
      html += '<td><span class="cig-badge badge-canceled">' + formatNumber(user.total_canceled) + '</span></td>';
      html += '<td><strong>' + formatCurrency(user.total_revenue) + '</strong></td>';
      html += '<td>' + formatDateTime(user.last_invoice_date) + '</td>';
      html += '</tr>';
    });
    
    $('#cig-fictive-users-tbody').html(html);
    renderFictiveUsersPagination();
    updateFictiveUsersSortArrows();
  }

  /**
   * Render Fictive Users Pagination
   */
  function renderFictiveUsersPagination() {
    var $container = $('#cig-fictive-users-pagination');
    var cp = fictiveUsersPagination.current_page;
    var tp = fictiveUsersPagination.total_pages;

    if (tp <= 1) {
      $container.html('');
      return;
    }

    var html = '<button class="cig-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>« Prev</button>';
    
    var startPage = Math.max(1, cp - 2);
    var endPage = Math.min(tp, cp + 2);
    
    if (startPage > 1) {
      html += '<button class="cig-page-btn" data-page="1">1</button>';
      if (startPage > 2) html += '<span style="padding:0 5px;color:#999;">...</span>';
    }
    
    for (var i = startPage; i <= endPage; i++) {
      html += '<button class="cig-page-btn ' + (i === cp ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>';
    }
    
    if (endPage < tp) {
      if (endPage < tp - 1) html += '<span style="padding:0 5px;color:#999;">...</span>';
      html += '<button class="cig-page-btn" data-page="' + tp + '">' + tp + '</button>';
    }
    
    html += '<button class="cig-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>Next »</button>';
    
    $container.html(html);
  }

  /**
   * Handle Sort for Fictive Users Table
   */
  function handleFictiveUsersSort() {
    var column = $(this).data('sort');
    if (fictiveUsersSort.column === column) {
      fictiveUsersSort.order = fictiveUsersSort.order === 'asc' ? 'desc' : 'asc';
    } else {
      fictiveUsersSort.column = column;
      fictiveUsersSort.order = 'desc';
    }
    sortFictiveUsers();
    updateFictiveUsersSortArrows();
  }

  /**
   * Sort Fictive Users client-side
   */
  function sortFictiveUsers() {
    fictiveUsersData.sort(function(a, b) {
      var aVal = a[fictiveUsersSort.column];
      var bVal = b[fictiveUsersSort.column];
      if (fictiveUsersSort.order === 'asc') {
        return aVal > bVal ? 1 : -1;
      }
      return aVal < bVal ? 1 : -1;
    });
    displayFictiveUsersPage();
  }

  /**
   * Update Sort Arrows for Fictive Users Table
   */
  function updateFictiveUsersSortArrows() {
    $('#cig-fictive-users-table .sortable').removeClass('active-sort').removeAttr('data-order');
    var $active = $('#cig-fictive-users-table .sortable[data-sort="' + fictiveUsersSort.column + '"]');
    $active.addClass('active-sort').attr('data-order', fictiveUsersSort.order);
  }

  function showUserDetail(userId) {
    var user = usersData.find(function(u){ return u.user_id == userId; });
    if (!user) return;
    currentUser = user; currentFilters.search = ''; $('#cig-invoice-search').val(''); $('#cig-user-payment-filter').val('all'); currentFilters.payment_method = 'all';
    var infoHtml = '<img src="' + user.user_avatar + '" alt="" class="user-info-avatar"><div class="user-info-details"><h3>' + escapeHtml(user.user_name) + '</h3><p class="user-info-email">' + escapeHtml(user.user_email) + '</p><div class="user-info-stats"><div class="user-info-stat"><span class="user-info-stat-label">Total Invoices</span><span class="user-info-stat-value">' + user.invoice_count + '</span></div><div class="user-info-stat"><span class="user-info-stat-label">Total Revenue</span><span class="user-info-stat-value">' + formatCurrency(user.total_revenue) + '</span></div><div class="user-info-stat"><span class="user-info-stat-label">Last Invoice</span><span class="user-info-stat-value">' + formatDateShort(user.last_invoice_date) + '</span></div></div></div>';
    $('#cig-user-info').html(infoHtml); $('#cig-user-detail-title').text(user.user_name + ' - ' + (cigStats.i18n?.invoices || 'Invoices')); $('#cig-users-panel').hide(); $('#cig-user-detail-panel').fadeIn(150); loadUserInvoices(user.user_id);
  }
  function hideUserDetail() { currentUser = null; invoicesData = []; $('#cig-user-detail-panel').hide(); $('#cig-users-panel').fadeIn(150); }
  function loadUserInvoices(userId) {
    $('#cig-user-invoices-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_invoices || 'Loading invoices...') + '</p></div></td></tr>');
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_user_invoices', nonce: cigStats.nonce, user_id: userId, date_from: currentFilters.date_from, date_to: currentFilters.date_to, payment_method: currentFilters.payment_method === 'all' ? '' : currentFilters.payment_method, status: 'standard', search: currentFilters.search },
      success: function(res) { if (res && res.success && res.data) { invoicesData = res.data.invoices; displayInvoicesPage(); } else { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">' + (cigStats.i18n?.no_invoices_found || 'No invoices found') + '</td></tr>'); } },
      error: function() { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_invoices || 'Error loading invoices') + '</td></tr>'); }
    });
  }
  function filterUserInvoices() { var filtered = invoicesData; if (currentFilters.search) { var term = currentFilters.search.toLowerCase(); filtered = invoicesData.filter(function(inv){ return String(inv.invoice_number || '').toLowerCase().includes(term); }); } invoicesPagination.current_page = 1; displayInvoicesPage(filtered); }
  function displayInvoicesPage(filtered) {
    filtered = filtered || invoicesData; invoicesPagination.total_pages = Math.ceil(filtered.length / invoicesPagination.per_page); var start = (invoicesPagination.current_page - 1) * invoicesPagination.per_page; var end = start + invoicesPagination.per_page; var pageData = filtered.slice(start, end);
    if (!pageData.length) { $('#cig-user-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">' + (cigStats.i18n?.no_invoices_found || 'No invoices found') + '</td></tr>'); $('#cig-invoices-pagination').html(''); return; }
    var html = '';
    pageData.forEach(function(inv){
      var paymentClass = 'payment-' + inv.payment_type;
      html += '<tr><td><strong>' + escapeHtml(inv.invoice_number) + '</strong></td><td>' + formatDateTime(inv.date) + '</td><td>' + formatNumber(inv.total_products) + '</td><td><span class="cig-badge badge-sold">' + formatNumber(inv.sold_items) + '</span></td><td><span class="cig-badge badge-reserved">' + formatNumber(inv.reserved_items) + '</span></td><td><span class="cig-badge badge-canceled">' + formatNumber(inv.canceled_items) + '</span></td><td>' + formatTotalWithDiscount(inv.invoice_total, inv.original_total) + '</td><td><span class="badge-payment ' + paymentClass + '">' + escapeHtml(inv.payment_label) + '</span></td><td><a href="' + inv.view_url + '" class="cig-btn-sm cig-btn-view" target="_blank">ნახვა</a> <a href="' + inv.edit_url + '" class="cig-btn-sm cig-btn-edit" target="_blank">რედაქტირება</a></td></tr>';
    });
    $('#cig-user-invoices-tbody').html(html); renderPagination('invoices');
  }

  function clearSummaryDropdowns() { $('#cig-summary-invoices').hide(); $('#cig-summary-outstanding').hide(); $('#cig-summary-products').hide(); }

  // --- TOGGLE INVOICES DROPDOWN ---
  function toggleInvoicesDropdown(method, titleText) {
    var $panel = $('#cig-summary-invoices');
    if ($panel.is(':visible') && $panel.data('method') === method) { 
        $panel.slideUp(150); 
        return; 
    }
    
    $panel.data('method', method);
    
    $('#cig-summary-products').hide(); $('#cig-summary-outstanding').hide();
    $('#cig-summary-invoices-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading || 'Loading...') + '</p></div></td></tr>');
    
    if(titleText) {
        $('#cig-summary-title').html('<strong>' + escapeHtml(titleText) + '</strong>');
    } else {
        $('#cig-summary-title').text(cigStats.i18n?.invoices || 'Invoices');
    }

    $panel.slideDown(150);
    
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { 
          action: 'cig_get_invoices_by_filters', 
          nonce: cigStats.nonce, 
          date_from: currentFilters.date_from, 
          date_to: currentFilters.date_to, 
          payment_method: method, // Pass specific method from card
          status: 'standard',  // General Overview tab ALWAYS uses 'standard' (Active invoices only)
          search: currentFilters.search
      },
      success: function(res) {
        if (res && res.success && res.data && res.data.invoices && res.data.invoices.length) {
          var html = '';
          res.data.invoices.forEach(function(inv){
            var paidClass = inv.paid > 0 ? 'color:#28a745;' : '';
            var dueClass = inv.due > 0.01 ? 'color:#dc3545;font-weight:bold;' : 'color:#999;';

            var paidHtml = formatCurrency(inv.paid || 0);
            if(inv.paid_breakdown) {
                paidHtml += inv.paid_breakdown;
            }

            // Click-to-Edit Date Pattern
            var rawDate = inv.date || '';
            var displayDate = rawDate ? rawDate.substring(0, 16) : '—';
            var inputVal = rawDate.replace(' ', 'T').substring(0, 16);

            html += '<tr>';
            html += '<td><strong>' + escapeHtml(inv.invoice_number || '') + '</strong></td>';
            html += '<td>' + escapeHtml(inv.customer) + '</td>';
            html += '<td>' + escapeHtml(inv.payment_methods) + '</td>';
            html += '<td>' + formatTotalWithDiscount(inv.total || 0, inv.original_total) + '</td>';
            html += '<td style="' + paidClass + '">' + paidHtml + '</td>';
            html += '<td style="' + dueClass + '">' + formatCurrency(inv.due || 0) + '</td>';
            // Click-to-Edit Date Cell
            html += '<td class="col-date-edit">';
            html += '  <div class="cig-view-date" data-id="' + inv.id + '">';
            html += '    <span class="date-text">' + displayDate + '</span>';
            html += '    <span class="dashicons dashicons-edit cig-btn-edit-date" title="Edit Date" style="cursor:pointer; color:#50529d; font-size:16px; vertical-align:middle; margin-left:5px;"></span>';
            html += '  </div>';
            html += '  <div class="cig-edit-date" style="display:none;">';
            html += '    <input type="datetime-local" class="cig-invoice-date-input" data-invoice-id="' + inv.id + '" value="' + inputVal + '" style="font-size:11px; width:100%; box-sizing:border-box;">';
            html += '  </div>';
            html += '</td>';
            html += '<td>' + escapeHtml(inv.author || '') + '</td>';
            html += '<td><a class="cig-btn-sm cig-btn-view" href="' + inv.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + inv.edit_url + '" target="_blank">რედაქტირება</a></td>';
            html += '</tr>';
          });
          $('#cig-summary-invoices-tbody').html(html);
        } else { $('#cig-summary-invoices-tbody').html('<tr class="no-results-row"><td colspan="9">' + (cigStats.i18n?.no_invoices_found || 'No invoices found') + '</td></tr>'); }
      },
      error: function() { $('#cig-summary-invoices-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_invoices || 'Error loading invoices') + '</td></tr>'); }
    });
  }

  // --- TOGGLE OUTSTANDING DROPDOWN ---
  function toggleOutstandingDropdown(titleText) {
      var $panel = $('#cig-summary-outstanding');
      if ($panel.is(':visible')) { $panel.slideUp(150); return; }
      
      if(titleText) {
          $('#cig-summary-outstanding .cig-summary-header h3').html('<strong>' + escapeHtml(titleText) + '</strong>');
      }

      $('#cig-summary-invoices').hide(); $('#cig-summary-products').hide();
      $('#cig-summary-outstanding-tbody').html('<tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_unpaid || 'Loading unpaid invoices...') + '</p></div></td></tr>');
      $panel.slideDown(150);
      $.ajax({
          url: cigStats.ajax_url, method: 'POST', dataType: 'json',
          data: { action: 'cig_get_invoices_by_filters', nonce: cigStats.nonce, date_from: currentFilters.date_from, date_to: currentFilters.date_to, payment_method: currentFilters.payment_method, status: 'outstanding', search: currentFilters.search },
          success: function(res) {
              if (res && res.success && res.data && res.data.invoices && res.data.invoices.length) {
                  var html = '';
                  res.data.invoices.forEach(function(inv){
                      var paidHtml = formatCurrency(inv.paid || 0);
                      if(inv.paid_breakdown) {
                          paidHtml += inv.paid_breakdown;
                      }

                      // Click-to-Edit Date Pattern
                      var rawDate = inv.date || '';
                      var displayDate = rawDate ? rawDate.substring(0, 16) : '—';
                      var inputVal = rawDate.replace(' ', 'T').substring(0, 16);

                      html += '<tr>';
                      html += '<td><strong>' + escapeHtml(inv.invoice_number) + '</strong></td>';
                      html += '<td>' + escapeHtml(inv.customer) + '</td>';
                      html += '<td>' + escapeHtml(inv.payment_methods) + '</td>';
                      html += '<td>' + formatTotalWithDiscount(inv.total, inv.original_total) + '</td>';
                      html += '<td style="color:#28a745;">' + paidHtml + '</td>';
                      html += '<td style="color:#dc3545;font-weight:bold;">' + formatCurrency(inv.due) + '</td>';
                      html += '<td>' + escapeHtml(inv.author) + '</td>';
                      // Click-to-Edit Date Cell
                      html += '<td class="col-date-edit">';
                      html += '  <div class="cig-view-date" data-id="' + inv.id + '">';
                      html += '    <span class="date-text">' + displayDate + '</span>';
                      html += '    <span class="dashicons dashicons-edit cig-btn-edit-date" title="Edit Date" style="cursor:pointer; color:#50529d; font-size:16px; vertical-align:middle; margin-left:5px;"></span>';
                      html += '  </div>';
                      html += '  <div class="cig-edit-date" style="display:none;">';
                      html += '    <input type="datetime-local" class="cig-invoice-date-input" data-invoice-id="' + inv.id + '" value="' + inputVal + '" style="font-size:11px; width:100%; box-sizing:border-box;">';
                      html += '  </div>';
                      html += '</td>';
                      html += '<td><a class="cig-btn-sm cig-btn-view" href="' + inv.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + inv.edit_url + '" target="_blank">რედაქტირება</a></td>';
                      html += '</tr>';
                  });
                  $('#cig-summary-outstanding-tbody').html(html);
              } else { $('#cig-summary-outstanding-tbody').html('<tr class="no-results-row"><td colspan="9">' + (cigStats.i18n?.no_outstanding || 'No outstanding invoices found.') + '</td></tr>'); }
          },
          error: function() { $('#cig-summary-outstanding-tbody').html('<tr class="no-results-row"><td colspan="9" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_data || 'Error loading data.') + '</td></tr>'); }
      });
  }

  function toggleProductsDropdown(status) {
    var $panel = $('#cig-summary-products');
    var title = status === 'reserved' ? (cigStats.i18n?.products_reserved || 'Products Reserved') : (cigStats.i18n?.products_sold || 'Products Sold');
    $('#cig-summary-products-title').text(title); $('#cig-col-qty-label').text(status === 'reserved' ? (cigStats.i18n?.reserved_qty || 'Reserved Qty') : (cigStats.i18n?.quantity_sold || 'Quantity Sold'));
    if ($panel.is(':visible') && $panel.data('status') === status) { $panel.slideUp(150); return; }
    $('#cig-summary-invoices').hide(); $('#cig-summary-outstanding').hide();
    $panel.data('status', status).slideDown(150);
    $('#cig-summary-products-tbody').html('<tr class="loading-row"><td colspan="8"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading || 'Loading...') + '</p></div></td></tr>');
    $.ajax({
      url: cigStats.ajax_url, method: 'POST', dataType: 'json',
      data: { action: 'cig_get_products_by_filters', nonce: cigStats.nonce, date_from: currentFilters.date_from, date_to: currentFilters.date_to, status: status, payment_method: currentFilters.payment_method, invoice_status: currentFilters.status },
      success: function(res) {
        if (res && res.success && res.data && res.data.products && res.data.products.length) {
          var html = '';
          res.data.products.forEach(function(it){
            var img = it.image ? '<img src="' + it.image + '" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #eee;border-radius:4px;background:#fff;">' : '';
            html += '<tr><td>' + img + '</td><td>' + escapeHtml(it.name || '') + '</td><td>' + escapeHtml(it.sku || '—') + '</td><td><strong>' + formatNumber(it.qty || 0) + '</strong></td><td>' + escapeHtml(it.invoice_number || '') + '</td><td>' + escapeHtml(it.author_name || '') + '</td><td>' + formatDateTime(it.date) + '</td><td><a class="cig-btn-sm cig-btn-view" href="' + it.view_url + '" target="_blank">ნახვა</a> <a class="cig-btn-sm cig-btn-edit" href="' + it.edit_url + '" target="_blank">რედაქტირება</a></td></tr>';
          });
          $('#cig-summary-products-tbody').html(html);
        } else { $('#cig-summary-products-tbody').html('<tr class="no-results-row"><td colspan="8">' + (cigStats.i18n?.no_products_found || 'No products found') + '</td></tr>'); }
      },
      error: function() { $('#cig-summary-products-tbody').html('<tr class="no-results-row"><td colspan="8" style="color:#dc3545;">' + (cigStats.i18n?.error_loading_products || 'Error loading products') + '</td></tr>'); }
    });
  }

  function renderPagination(type) {
    var cfg = (type === 'users') ? usersPagination : invoicesPagination;
    var $container = (type === 'users') ? $('#cig-users-pagination') : $('#cig-invoices-pagination');
    if (cfg.total_pages <= 1) { $container.html(''); return; }
    var cp = cfg.current_page, tp = cfg.total_pages;
    var html = '<button class="cig-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>« ' + (cigStats.i18n?.prev || 'Prev') + '</button>';
    var startPage = Math.max(1, cp - 2); var endPage = Math.min(tp, cp + 2);
    if (startPage > 1) { html += '<button class="cig-page-btn" data-page="1">1</button>'; if (startPage > 2) html += '<span style="padding:0 5px;color:#999;">...</span>'; }
    for (var i = startPage; i <= endPage; i++) { html += '<button class="cig-page-btn ' + (i === cp ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>'; }
    if (endPage < tp) { if (endPage < tp - 1) html += '<span style="padding:0 5px;color:#999;">...</span>'; html += '<button class="cig-page-btn" data-page="' + tp + '">' + tp + '</button>'; }
    html += '<button class="cig-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>' + (cigStats.i18n?.next || 'Next') + ' »</button>';
    $container.html(html);
  }

  function handleExport() {
    var base = cigStats.ajax_url.replace('admin-ajax.php', 'admin.php');
    var params = ['cig_export=statistics', '_wpnonce=' + encodeURIComponent(cigStats.export_nonce)];
    if (currentFilters.date_from) params.push('date_from=' + encodeURIComponent(currentFilters.date_from));
    if (currentFilters.date_to) params.push('date_to=' + encodeURIComponent(currentFilters.date_to));
    if (currentFilters.status) params.push('status=' + encodeURIComponent(currentFilters.status));
    window.location.href = base + '?' + params.join('&');
  }

  function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    autoRefreshInterval = setInterval(function() { 
        var activeTab = $('.nav-tab-active').data('tab');
        if (activeTab === 'overview') {
            loadStatistics(true); 
            if (currentUser) loadUserInvoices(currentUser.user_id);
        }
    }, 300000);
  }

  // --- CUSTOMER INSIGHT LOGIC ---

  function loadCustomers() {
      $('#cig-customers-tbody').html('<tr class="loading-row"><td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p>' + (cigStats.i18n?.loading_customers || 'Loading customers...') + '</p></div></td></tr>');
      
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_customer_insights',
              nonce: cigStats.nonce,
              paged: custPagination.current_page,
              per_page: custPagination.per_page,
              search: custFilters.search,
              date_from: custFilters.dateFrom,
              date_to: custFilters.dateTo
          },
          success: function(res) {
              if(res.success && res.data) {
                  renderCustomerTable(res.data.customers);
                  custPagination.total_pages = res.data.total_pages; 
                  renderCustomerPagination();
              } else {
                  $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;">' + (cigStats.i18n?.no_customers_found || 'No customers found') + '</td></tr>');
                  $('#cig-customers-pagination').empty();
              }
          },
          error: function() {
              $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;color:red;">' + (cigStats.i18n?.error_loading_data || 'Error loading data') + '</td></tr>');
          }
      });
  }

  function renderCustomerTable(customers) {
      if(!customers || !customers.length) {
          $('#cig-customers-tbody').html('<tr><td colspan="6" style="text-align:center;">' + (cigStats.i18n?.no_customers_found || 'No data found') + '</td></tr>');
          return;
      }
      var html = '';
      customers.forEach(function(c) {
          var taxDisplay = escapeHtml(c.tax_id || '—');
          
          html += '<tr class="cig-customer-row" data-customer-id="' + c.id + '" style="cursor:pointer;">';
          html += '<td><strong>' + escapeHtml(c.name) + '</strong></td>';
          html += '<td style="color:#50529d;font-weight:bold;">' + taxDisplay + '</td>';
          html += '<td>' + c.count + '</td>';
          html += '<td>' + formatCurrency(c.revenue) + '</td>';
          html += '<td style="color:#28a745;">' + formatCurrency(c.paid) + '</td>';
          html += '<td style="color:#dc3545;font-weight:bold;">' + formatCurrency(c.due) + '</td>';
          html += '</tr>';
      });
      $('#cig-customers-tbody').html(html);
  }

  function renderCustomerPagination() {
      var $con = $('#cig-customers-pagination');
      $con.empty();
      if(custPagination.total_pages <= 1) return;
      
      var cp = custPagination.current_page;
      var tp = custPagination.total_pages;
      
      var html = '<button class="cig-page-btn cig-cust-page-btn" data-page="' + (cp - 1) + '" ' + (cp <= 1 ? 'disabled' : '') + '>«</button>';
      html += ' <span style="font-size:12px;margin:0 5px;">Page ' + cp + ' of ' + tp + '</span> ';
      html += '<button class="cig-page-btn cig-cust-page-btn" data-page="' + (cp + 1) + '" ' + (cp >= tp ? 'disabled' : '') + '>»</button>';
      
      $con.html(html);
  }

  function showCustomerDetail(custId) {
      $('#cig-customer-list-panel').slideUp();
      $('#cig-customer-detail-panel').slideDown();
      $('#cig-cust-invoices-tbody').html('<tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div></div></td></tr>');
      
      $.ajax({
          url: cigStats.ajax_url,
          method: 'POST',
          dataType: 'json',
          data: {
              action: 'cig_get_customer_invoices_details',
              nonce: cigStats.nonce,
              customer_id: custId,
              date_from: custFilters.dateFrom,
              date_to: custFilters.dateTo
          },
          success: function(res) {
              if(res.success && res.data) {
                  $('#cig-customer-detail-title').text(res.data.customer_name + ' - ' + (cigStats.i18n?.invoices || 'Invoices'));
                  renderCustomerInvoices(res.data.invoices);
              } else {
                  $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">' + (cigStats.i18n?.no_customer_invoices || 'No invoices found') + '</td></tr>');
              }
          },
          error: function() {
              $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">' + (cigStats.i18n?.error_loading_details || 'Error loading details') + '</td></tr>');
          }
      });
  }

  function renderCustomerInvoices(invoices) {
      if(!invoices || !invoices.length) {
          $('#cig-cust-invoices-tbody').html('<tr><td colspan="7">' + (cigStats.i18n?.no_customer_invoices || 'No invoices found') + '</td></tr>');
          return;
      }
      var html = '';
      invoices.forEach(function(inv) {
          var statusBadge = '';
          if(inv.status === 'Paid') {
              statusBadge = '<span class="cig-badge badge-sold">Paid</span>';
          } else {
              statusBadge = '<span class="cig-badge badge-canceled">Unpaid</span>';
          }

          html += '<tr>';
          html += '<td><a href="' + inv.view_url + '" target="_blank" style="font-weight:bold;color:#50529d;">' + inv.number + '</a></td>';
          html += '<td>' + inv.date + '</td>';
          html += '<td>' + formatTotalWithDiscount(inv.total, inv.original_total) + '</td>';
          html += '<td style="color:#28a745;">' + formatCurrency(inv.paid) + '</td>';
          html += '<td style="color:#dc3545;">' + formatCurrency(inv.due) + '</td>';
          html += '<td>' + statusBadge + '</td>';
          html += '<td><a href="' + inv.view_url + '" class="cig-btn-sm cig-btn-view" target="_blank">View</a></td>';
          html += '</tr>';
      });
      $('#cig-cust-invoices-tbody').html(html);
  }

  // Utility functions
  function formatNumber(num) { return parseFloat(num || 0).toLocaleString('en-US'); }
  function formatCurrency(amount) { return parseFloat(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₾'; }
  function formatTotalWithDiscount(total, originalTotal) {
    if (originalTotal && originalTotal > total + 0.01) {
      return '<del style="color:#999;font-size:0.85em;font-weight:normal;">' + formatCurrency(originalTotal) + '</del> <strong>' + formatCurrency(total) + '</strong>';
    }
    return '<strong>' + formatCurrency(total) + '</strong>';
  }
  function formatDateTime(dateString) { if (!dateString) return '-'; var date = new Date(dateString.replace(' ', 'T')); return date.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  function formatDateShort(dateString) { if (!dateString) return '-'; var date = new Date(dateString.replace(' ', 'T')); return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
  function formatDate(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0'); }
  function escapeHtml(text) { var map = { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }; return String(text || '').replace(/[&<>"']/g, function(m){ return map[m]; }); }

  /**
   * Calculate date range based on filter type
   * @param {string} filter - Filter type: 'today', 'this_week', 'this_month', 'last_30_days', 'all_time'
   * @returns {object} Object with 'from' and 'to' date strings (YYYY-MM-DD format)
   */
  function calculateDateRange(filter) {
    var today = new Date();
    var from = '', to = '';
    switch(filter) {
      case 'today':
        from = to = formatDate(today);
        break;
      case 'this_week':
        var ws = new Date(today);
        ws.setDate(ws.getDate() + ((ws.getDay() === 0 ? -6 : 1) - ws.getDay()));
        from = formatDate(ws);
        to = formatDate(today);
        break;
      case 'this_month':
        from = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
        to = formatDate(today);
        break;
      case 'last_30_days':
        var p30 = new Date(today);
        p30.setDate(today.getDate() - 30);
        from = formatDate(p30);
        to = formatDate(today);
        break;
      case 'all_time':
        from = '';
        to = '';
        break;
    }
    return { from: from, to: to };
  }

});