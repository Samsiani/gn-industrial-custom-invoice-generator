jQuery(function ($) {
  'use strict';

  var currentFilter = 'all';
  var currentStatus = 'standard'; // Default: Active Only
  var searchTerm = '';
  var searchTimeout = null;
  var expiringData = [];
  var currentPage = 1;
  var totalPages = 1;

  // Initialize
  $(document).ready(function() {
    loadDashboardStats();
    loadExpiringReservations();
    bindEvents();
  });

  // Bind all events
  function bindEvents() {
    // My Invoices button click
    $(document).on('click', '#cig-mini-btn-invoices', function(e) {
      e.preventDefault();
      toggleInvoicesDropdown();
    });

    // Close dropdown
    $(document).on('click', '.cig-mini-close-dropdown', function() {
      closeAllDropdowns();
    });

    // Click outside to close
    $(document).on('click', function(e) {
      if (!$(e.target).closest('.cig-mini-dashboard').length) {
        closeAllDropdowns();
      }
    });

    // Quick filters (Date)
    $(document).on('click', '.cig-mini-filter-btn', function() {
      $('.cig-mini-filter-btn').removeClass('active');
      $(this).addClass('active');
      currentFilter = $(this).data('filter');
      currentPage = 1;
      loadMyInvoices();
    });

    // Status Filter
    $(document).on('change', '#cig-mini-status-filter', function() {
        currentStatus = $(this).val();
        currentPage = 1;
        loadMyInvoices();
    });

    // Invoice search
    $(document).on('input', '#cig-mini-invoice-search', function() {
      clearTimeout(searchTimeout);
      var term = $(this).val().trim();
      searchTimeout = setTimeout(function() {
        searchTerm = term;
        currentPage = 1;
        loadMyInvoices();
      }, 300);
    });

    // Pagination clicks
    $(document).on('click', '.cig-mini-page-btn', function() {
      var page = $(this).data('page');
      if (page && page !== currentPage) {
        currentPage = page;
        loadMyInvoices();
      }
    });

    // Expiring reservations click
    $(document).on('click', '#cig-mini-stat-reserved, #cig-expiring-badge', function() {
      if (expiringData.length > 0) {
        toggleExpiringDropdown();
      }
    });
  }

  // Load dashboard stats
  function loadDashboardStats() {
    $.ajax({
      url: cigAjax.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'cig_get_my_invoices',
        nonce: cigAjax.nonce,
        filter: 'all',
        search: ''
      },
      success: function(res) {
        if (res && res.success && res.data) {
          updateStats(res.data.stats);
        }
      },
      error: function() {
        $('#cig-mini-stat-invoices .cig-mini-stat-value').html('<span style="color:#dc3545;">' + (cigAjax.i18n?.error || 'Error') + '</span>');
        $('#cig-mini-stat-last .cig-mini-stat-value').html('<span style="color:#dc3545;">' + (cigAjax.i18n?.error || 'Error') + '</span>');
        $('#cig-mini-stat-reserved .cig-mini-stat-value').html('<span style="color:#dc3545;">' + (cigAjax.i18n?.error || 'Error') + '</span>');
      }
    });
  }

  // Update stats
  function updateStats(stats) {
    $('#cig-mini-stat-invoices .cig-mini-stat-value').text(stats.total_invoices || 0);
    
    if (stats.last_invoice_date) {
      var date = new Date(stats.last_invoice_date.replace(' ', 'T'));
      var today = new Date();
      var isToday = date.toDateString() === today.toDateString();
      var displayDate = isToday ? (cigAjax.i18n?.today || 'Today') : formatDate(stats.last_invoice_date);
      $('#cig-mini-stat-last .cig-mini-stat-value').text(displayDate);
    } else {
      $('#cig-mini-stat-last .cig-mini-stat-value').text(cigAjax.i18n?.never || 'Never');
    }
    
    $('#cig-mini-stat-reserved .cig-mini-stat-value').text(stats.total_reserved || 0);
  }

  // Load expiring reservations
  function loadExpiringReservations() {
    $.ajax({
      url: cigAjax.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'cig_get_expiring_reservations',
        nonce: cigAjax.nonce
      },
      success: function(res) {
        if (res && res.success && res.data) {
          expiringData = res.data.expiring || [];
          if (res.data.count > 0) {
            $('#cig-expiring-badge').show().find('.cig-expiring-count').text(res.data.count);
            $('#cig-mini-stat-reserved').addClass('cig-expiring-alert');
          }
        }
      }
    });
  }

  // Toggle invoices dropdown
  function toggleInvoicesDropdown() {
    var $dropdown = $('#cig-mini-invoices-dropdown');
    
    if ($dropdown.is(':visible')) {
      closeAllDropdowns();
    } else {
      closeAllDropdowns();
      $dropdown.fadeIn(200);
      loadMyInvoices();
    }
  }

  // Toggle expiring dropdown
  function toggleExpiringDropdown() {
    var $dropdown = $('#cig-expiring-dropdown');
    
    if ($dropdown.is(':visible')) {
      closeAllDropdowns();
    } else {
      closeAllDropdowns();
      $dropdown.fadeIn(200);
      renderExpiringList();
    }
  }

  // Close all dropdowns
  function closeAllDropdowns() {
    $('.cig-mini-dropdown').fadeOut(200);
  }

  // Load my invoices
  function loadMyInvoices() {
    // Note: colspan is 7 because we have 7 columns in TH
    $('#cig-mini-invoices-tbody').html('<tr class="cig-mini-loading-row"><td colspan="7"><div class="cig-mini-loading"><div class="cig-mini-spinner"></div><p>' + (cigAjax.i18n?.loading_invoices || 'Loading invoices...') + '</p></div></td></tr>');

    $.ajax({
      url: cigAjax.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'cig_get_my_invoices',
        nonce: cigAjax.nonce,
        filter: currentFilter,
        search: searchTerm,
        status: currentStatus,
        paged: currentPage
      },
      success: function(res) {
        if (res && res.success && res.data) {
          totalPages = res.data.total_pages || 1;
          currentPage = res.data.current_page || 1;
          renderInvoices(res.data.invoices);
          renderPagination();
        } else {
          $('#cig-mini-invoices-tbody').html('<tr><td colspan="7" class="cig-mini-no-results">' + (cigAjax.i18n?.no_invoices_found || 'No invoices found') + '</td></tr>');
          $('#cig-mini-pagination').remove();
        }
      },
      error: function() {
        $('#cig-mini-invoices-tbody').html('<tr><td colspan="7" class="cig-mini-no-results" style="color:#dc3545;">' + (cigAjax.i18n?.error_loading_invoices || 'Error loading invoices') + '</td></tr>');
        $('#cig-mini-pagination').remove();
      }
    });
  }

  // Render invoices
  function renderInvoices(invoices) {
    if (!invoices || invoices.length === 0) {
      $('#cig-mini-invoices-tbody').html('<tr><td colspan="7" class="cig-mini-no-results">' + (cigAjax.i18n?.no_invoices_found || 'No invoices found') + '</td></tr>');
      return;
    }

    var html = '';
    invoices.forEach(function(invoice) {
      var statusIcons = '';
      if (invoice.has_sold) statusIcons += '<span class="cig-status-icon status-sold" title="' + (cigAjax.i18n?.sold || 'Sold') + '">✓</span>';
      if (invoice.has_reserved) statusIcons += '<span class="cig-status-icon status-reserved" title="' + (cigAjax.i18n?.reserved || 'Reserved') + '">⏳</span>';
      if (invoice.has_canceled) statusIcons += '<span class="cig-status-icon status-canceled" title="' + (cigAjax.i18n?.canceled || 'Canceled') + '">✗</span>';

      var paymentClass = 'payment-' + invoice.payment_type;
      
      // Type Badge (Correct logic)
      var invStatus = invoice.status || currentStatus || 'standard';
      var typeBadge = '';
      
      if (invStatus === 'fictive') {
          typeBadge = '<span class="cig-mini-type-badge cig-type-fictive">FICTIVE</span>';
      } else {
          typeBadge = '<span class="cig-mini-type-badge cig-type-active">ACTIVE</span>';
      }

      html += '<tr>';
      // Column 1: Invoice #
      html += '<td><a href="' + invoice.edit_url + '" class="cig-mini-invoice-number">' + escapeHtml(invoice.invoice_number) + '</a></td>';
      // Column 2: Type
      html += '<td>' + typeBadge + '</td>';
      // Column 3: Date
      html += '<td>' + formatDateTime(invoice.date) + '</td>';
      // Column 4: Total
      html += '<td><strong>' + formatCurrency(invoice.invoice_total) + '</strong></td>';
      // Column 5: Payment
      html += '<td><span class="cig-mini-payment-badge ' + paymentClass + '">' + escapeHtml(invoice.payment_label) + '</span></td>';
      // Column 6: Status (Icons)
      html += '<td><div class="cig-mini-status-icons">' + statusIcons + '</div></td>';
      // Column 7: Actions
      html += '<td><div class="cig-mini-actions">';
      html += '<a href="' + invoice.view_url + '" class="cig-mini-action-btn cig-mini-btn-view" target="_blank">' + (cigAjax.i18n?.view || 'View') + '</a>';
      html += '<a href="' + invoice.edit_url + '" class="cig-mini-action-btn cig-mini-btn-edit">' + (cigAjax.i18n?.edit || 'Edit') + '</a>';
      html += '</div></td>';
      html += '</tr>';
    });

    $('#cig-mini-invoices-tbody').html(html);
  }

  // Render expiring list
  function renderExpiringList() {
    if (expiringData.length === 0) {
      $('#cig-expiring-list').html('<div class="cig-mini-no-results">' + (cigAjax.i18n?.no_reservations_expiring || 'No reservations expiring soon') + '</div>');
      return;
    }

    var html = '';
    expiringData.forEach(function(item) {
      var urgencyClass = item.days_left <= 1 ? 'urgency-high' : 'urgency-medium';
      var urgencyText = item.days_left === 1 
          ? (cigAjax.i18n?.day_left || '1 day left') 
          : (cigAjax.i18n?.days_left || '%d days left').replace('%d', item.days_left);

      html += '<div class="cig-expiring-item">';
      html += '<div class="cig-expiring-header">';
      html += '<div class="cig-expiring-product">' + escapeHtml(item.product_name) + '</div>';
      html += '<span class="cig-expiring-urgency ' + urgencyClass + '">' + urgencyText + '</span>';
      html += '</div>';
      html += '<div class="cig-expiring-details">';
      html += '<strong>Invoice:</strong> <a href="' + item.edit_url + '" class="cig-expiring-invoice">' + escapeHtml(item.invoice_number) + '</a> | ';
      html += '<strong>SKU:</strong> ' + escapeHtml(item.product_sku || 'N/A') + ' | ';
      html += '<strong>Qty:</strong> ' + item.quantity + ' | ';
      html += '<strong>Expires:</strong> ' + formatDate(item.expires_date);
      html += '</div>';
      html += '</div>';
    });

    $('#cig-expiring-list').html(html);
  }

  // Render pagination
  function renderPagination() {
    $('#cig-mini-pagination').remove();
    if (totalPages <= 1) return;

    var html = '<div id="cig-mini-pagination" class="cig-mini-pagination">';

    // Previous button
    if (currentPage > 1) {
      html += '<button type="button" class="cig-mini-page-btn cig-mini-page-arrow" data-page="' + (currentPage - 1) + '">&laquo;</button>';
    }

    // Page numbers with ellipsis
    var pages = [];
    var maxVisible = 5;
    var startPage, endPage;

    if (totalPages <= maxVisible) {
      startPage = 1;
      endPage = totalPages;
    } else {
      startPage = Math.max(1, currentPage - 2);
      endPage = Math.min(totalPages, startPage + maxVisible - 1);
      if (endPage - startPage < maxVisible - 1) {
        startPage = Math.max(1, endPage - maxVisible + 1);
      }
    }

    if (startPage > 1) {
      html += '<button type="button" class="cig-mini-page-btn" data-page="1">1</button>';
      if (startPage > 2) html += '<span class="cig-mini-page-dots">...</span>';
    }

    for (var i = startPage; i <= endPage; i++) {
      var activeClass = i === currentPage ? ' active' : '';
      html += '<button type="button" class="cig-mini-page-btn' + activeClass + '" data-page="' + i + '">' + i + '</button>';
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) html += '<span class="cig-mini-page-dots">...</span>';
      html += '<button type="button" class="cig-mini-page-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
    }

    // Next button
    if (currentPage < totalPages) {
      html += '<button type="button" class="cig-mini-page-btn cig-mini-page-arrow" data-page="' + (currentPage + 1) + '">&raquo;</button>';
    }

    html += '</div>';

    // Insert after the table, inside the dropdown content
    $('.cig-mini-dropdown-content').after(html);
  }

  // Utility functions
  function formatCurrency(amount) {
    return parseFloat(amount).toLocaleString('en-US', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }) + ' ₾';
  }

  function formatDateTime(dateString) {
    if (!dateString) return '-';
    var date = new Date(dateString.replace(' ', 'T'));
    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function formatDate(dateString) {
    if (!dateString) return '-';
    var date = new Date(dateString.replace(' ', 'T'));
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  function escapeHtml(text) {
    var map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
  }
});