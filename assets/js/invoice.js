jQuery(function ($) {
  'use strict';

  // --- 0. PRINT BUTTON (Always Active) ---
  $(document).on('click', '#btn-print-invoice', function () { window.print(); });

  // 1. Permission Check
  if (!window.cigAjax || !parseInt(cigAjax.capable, 10)) {
    if ($('.cig-invoice-generator').length) {
      $('.cig-invoice-generator').html(
        '<div class="notice notice-warning" style="padding:12px;">' +
          (cigAjax?.i18n?.no_permission || 'Not allowed') +
        '</div>'
      );
    }
    return;
  }

  // 2. Global Variables
  var editMode = parseInt(cigAjax.editMode, 10) === 1;
  var invoiceId = parseInt(cigAjax.invoiceId, 10) || 0;
  var defaultReservationDays = parseInt(cigAjax.default_reservation_days, 10) || 30;
  var stockCheckTimeout = null;
  var stockCheckXhr = null;
  var isReadOnly = cigAjax.isReadOnly === '1'; 
  
  var paymentHistory = [];

  // --- INITIALIZATION ---
  $(document).ready(function() {
      if (isReadOnly) {
          disableEditor();
      }
      
      if (!editMode) {
          loadFromCart(); // Now loads from DB (cigAjax.initialCart)
          // Invoice number is auto-assigned by the backend on save — no pre-fetch needed
          if (!$('#invoice-number').val()) {
              $('#invoice-number').attr('placeholder', cigAjax.i18n?.auto_number || 'Auto').val('');
          }
      } else {
          prefillEditData();
      }
  });

  function disableEditor() {
      $('#cig-invoice-generator input, #cig-invoice-generator select, #cig-invoice-generator textarea').prop('disabled', true);
      $('.editable-field, .editable-value').attr('contenteditable', 'false').css('cursor', 'default');
      $('.btn-add-row, .btn-remove-row, .btn-save-invoice, .cig-add-payment-btn, .btn-delete-payment, .qty-btn').hide();
      $('#btn-print-invoice').prop('disabled', false);
      $('.cig-invoice-generator').addClass('cig-readonly-mode');
  }

  // ---------------------------------------------------------
  // CART LOADING LOGIC (UPDATED: DB BASED)
  // ---------------------------------------------------------
  function loadFromCart() {
      // Get cart data passed from PHP (User Meta)
      var cart = cigAjax.initialCart || [];

      if (!Array.isArray(cart) || cart.length === 0) return;

      var $firstRow = $('#invoice-items tr').first();
      // Only remove empty first row if it's truly empty
      if ($firstRow.find('.product-search').val() === '') {
          $firstRow.remove();
      }

      var $tbody = $('#invoice-items');

      cart.forEach(function(item) {
          var rowNum = $tbody.children().length + 1;
          var price = parseFloat(item.price || 0).toFixed(2);
          var qty = parseFloat(item.qty || 1);
          var total = (price * qty).toFixed(2);
          var img = item.image || cigAjax.placeholder_img;
          var origPrice = parseFloat(item.original_price || item.price || 0);

          var html = '<tr class="cig-item-row" data-original-price="' + origPrice + '">' +
              '<td class="col-n">' + rowNum + '</td>' +
              '<td class="col-name">' +
                  '<input type="text" class="product-search" data-product-id="' + (item.id||0) + '" data-sku="' + (item.sku||'') + '" value="' + (item.name||'') + '">' +
                  '<div class="name-sub"><span class="name-sku-label">' + (cigAjax.i18n?.code_label || 'Code:') + '</span> <span class="name-sku-value">' + (item.sku||'—') + '</span></div>' +
              '</td>' +
              '<td class="col-image">' +
                  '<img class="product-image" src="' + img + '">' +
                  '<select class="warranty-period" style="width:100%;margin-top:5px;font-size:10px;"><option value="">---</option><option value="6m">' + (cigAjax.i18n?.warranty_6m || '6 Months') + '</option><option value="1y">' + (cigAjax.i18n?.warranty_1y || '1 Year') + '</option><option value="2y">' + (cigAjax.i18n?.warranty_2y || '2 Years') + '</option><option value="3y">' + (cigAjax.i18n?.warranty_3y || '3 Years') + '</option></select>' +
              '</td>' +
              '<td class="col-brand"><input type="text" class="product-brand" readonly value="' + (item.brand||'') + '"></td>' +
              '<td class="col-desc"><textarea class="product-desc">' + (item.desc||'') + '</textarea></td>' +
              '<td class="col-qty">' +
                  '<div class="quantity-wrapper">' +
                      '<input type="number" class="quantity" min="1" value="' + qty + '">' +
                      '<div class="qty-btn-group"><button type="button" class="qty-btn qty-increase">▲</button><button type="button" class="qty-btn qty-decrease">▼</button></div>' +
                  '</div>' +
              '</td>' +
              '<td class="col-price"><input type="number" class="price" step="0.01" value="' + price + '"><span class="cig-original-price" style="display:none;"></span></td>' +
              '<td class="col-total"><input type="text" class="row-total" readonly value="' + total + '"></td>' +
              '<td class="col-status no-print">' +
                  '<select class="product-status">' +
                      '<option value="none">---</option>' +
                      '<option value="sold">' + (cigAjax.i18n?.sold || 'Sold') + '</option>' +
                      '<option value="reserved">' + (cigAjax.i18n?.reserved || 'Reserved') + '</option>' +
                      '<option value="canceled">' + (cigAjax.i18n?.canceled || 'Canceled') + '</option>' +
                  '</select>' +
                  '<input type="number" class="reservation-days" min="1" max="90" value="' + defaultReservationDays + '" style="width:60px;margin-top:3px;display:none;">' +
              '</td>' +
              '<td class="col-actions no-print"><button type="button" class="btn-remove-row">X</button></td>' +
          '</tr>';

          var $row = $(html);
          $tbody.append($row);
          
          $row.find('.product-status').val('none');

          initAutocomplete($row.find('.product-search'));
          checkStock($row);
      });

      updateGrandTotal();
  }

  // ---------------------------------------------------------
  // CUSTOMER LOGIC
  // ---------------------------------------------------------
  function saveCurrentEditableField() {
    if (isReadOnly) return;
    var $currentInput = $('.buyer-field-input');
    if (!$currentInput.length) return;
    var $host = $currentInput.data('host');
    var val = ($currentInput.val() || '').trim();
    var placeholder = $host.data('placeholder');
    if (val === '') { $host.text(placeholder).addClass('is-empty'); if ($host.is('li > span')) $host.closest('li').addClass('is-empty'); } 
    else { $host.text(val).removeClass('is-empty'); if ($host.is('li > span')) $host.closest('li').removeClass('is-empty'); }
    $host.show(); $currentInput.remove();
  }

  function fillCustomerData(data) {
      var $bf = $('.buyer-details');
      var setVal = function(el, val) { if(val && val !== '') { el.text(val).removeClass('is-empty'); if(el.is('li > span')) el.closest('li').removeClass('is-empty'); } };
      if (data.tax_id) setVal($bf.find('strong.editable-field').eq(1), data.tax_id);
      if (data.value && $bf.find('strong.editable-field').eq(0).is(':visible')) setVal($bf.find('strong.editable-field').eq(0), data.value);
      setVal($bf.find('span.editable-value').eq(0), data.address); setVal($bf.find('span.editable-value').eq(1), data.phone); setVal($bf.find('span.editable-value').eq(2), data.email);
  }

  function initCustomerAutocomplete($input) {
      $input.autocomplete({
          minLength: 2,
          source: function(request, response) { $.ajax({ url: cigAjax.ajax_url, method: 'POST', dataType: 'json', data: { action: 'cig_search_customers', nonce: cigAjax.nonce, term: request.term }, success: function(data) { response(data || []); }, error: function() { response([]); } }); },
          select: function(event, ui) { $input.val(ui.item.value || ui.item.tax_id); fillCustomerData(ui.item); return false; }
      });
  }

  $(document).on('click', '.buyer-details [data-placeholder]', function () {
    if (isReadOnly) return;
    var $host = $(this); if ($host.is('input') || $host.is(':hidden')) return;
    saveCurrentEditableField();
    var isEmpty = $host.hasClass('is-empty') || $host.closest('li').hasClass('is-empty');
    var currentValue = isEmpty ? '' : $host.text();
    var cls = 'buyer-field-input'; if ($host.is('strong')) cls += ' editable-field';
    var $input = $('<input type="text" class="' + cls + '">').val(currentValue).data('host', $host);
    $host.hide().after($input); $input.focus();
    var strongIndex = $('.buyer-details strong.editable-field').index($host);
    if (strongIndex === 0 || strongIndex === 1) initCustomerAutocomplete($input);
  });
  $(document).on('blur', '.buyer-field-input', function() { setTimeout(saveCurrentEditableField, 200); });
  $(document).on('keypress', '.buyer-field-input', function (e) { if (e.which === 13) saveCurrentEditableField(); });

  // ---------------------------------------------------------
  // PRODUCT LOGIC
  // ---------------------------------------------------------
  $(document).on('change', '.product-status', function () {
    var $row = $(this).closest('tr');
    var status = $(this).val();
    var $daysInput = $row.find('.reservation-days');
    if (status === 'reserved') { $daysInput.show(); if (!$daysInput.val() || parseInt($daysInput.val(), 10) <= 0) $daysInput.val(defaultReservationDays); } 
    else { $daysInput.hide(); }
    updateGrandTotal(); 
  });

  // ---------------------------------------------------------
  // PAYMENT HISTORY
  // ---------------------------------------------------------
  function renderPaymentHistory() {
      var $tbody = $('#cig-payment-history-tbody');
      if ($tbody.length === 0) return;
      $tbody.empty();
      var totalPaid = 0;
      var cashTotal = 0;
      var consignmentTotal = 0;
      var grandTotal = parseFloat($('#grand-total').text()) || 0;

      if (paymentHistory.length > 0) {
          paymentHistory.forEach(function(pay, index) {
              var amount = parseFloat(pay.amount);
              var method = (pay.method || '').toLowerCase();
              totalPaid += amount;
              
              // Separate cash vs consignment
              if (method === 'consignment') {
                  consignmentTotal += amount;
              } else {
                  cashTotal += amount;
              }
              
              var labels = { 'company_transfer': 'კომპანიის გადარიცხვა', 'cash': 'ქეში', 'consignment': 'კონსიგნაცია', 'credit': 'განვადება', 'other': 'სხვა' };
              var methodLabel = labels[pay.method] || pay.method;
              var rowHtml = '<tr><td style="padding:10px; border-bottom:1px solid #eee; color:#555;">' + (pay.date || '-') + '</td><td style="padding:10px; border-bottom:1px solid #eee; color:#555;">' + (methodLabel || '-') + '</td><td style="padding:10px; border-bottom:1px solid #eee; text-align:right; font-weight:bold; color:#333;">' + amount.toFixed(2) + ' ₾</td><td style="padding:10px; border-bottom:1px solid #eee; text-align:center;">' + (!isReadOnly ? '<span class="dashicons dashicons-trash btn-delete-payment" data-index="' + index + '" style="color:#dc3545; cursor:pointer; font-size:16px; opacity:0.7;" title="წაშლა"></span>' : '') + '</td></tr>';
              $tbody.append(rowHtml);
          });
      } else { $tbody.html('<tr><td colspan="4" style="text-align:center; padding:15px; color:#999; font-style:italic;">' + (cigAjax.i18n?.no_payments_recorded || 'No payments recorded') + '</td></tr>'); }

      var remaining = grandTotal - totalPaid;
      if (Math.abs(remaining) < 0.01) remaining = 0;

      $('#disp-grand-total').text(grandTotal.toFixed(2) + ' ₾');
      
      // Consignment Visual Logic - replicate PHP template logic
      // Row 2: Show "Cash Paid" IF cashTotal > 0
      if (cashTotal > 0) {
          $('#disp-paid-row').show();
          $('#disp-paid-total').text(cashTotal.toFixed(2) + ' ₾');
          $('#disp-paid-label').text(cigAjax.i18n?.paid_label || 'Paid:');
      } else {
          $('#disp-paid-row').hide();
      }
      
      // Row 3: Show "Consignment" IF consignmentTotal > 0
      if (consignmentTotal > 0) {
          $('#disp-consignment-row').show();
          $('#disp-consignment-total').text(consignmentTotal.toFixed(2) + ' ₾');
      } else {
          $('#disp-consignment-row').hide();
      }
      
      // Row 4: Show "Remaining/Due" ONLY IF remaining > 0.01 (If consignment covers balance, hide this row)
      if (remaining > 0.01) {
          $('#disp-remaining-row').show();
          $('#disp-remaining').css('color', '#dc3545').text(remaining.toFixed(2) + ' ₾');
      } else if (remaining < -0.01) {
          // Overpaid scenario
          $('#disp-remaining-row').show();
          $('#disp-remaining').css('color', '#dc3545').text(remaining.toFixed(2) + ' ₾ (' + (cigAjax.i18n?.overpaid || 'overpaid') + ')');
      } else {
          // Remaining is 0 or covered by payments - hide remaining row
          $('#disp-remaining-row').hide();
      }
  }

  $(document).on('click', '#btn-add-payment', function() {
      if (isReadOnly) return;
      var amount = parseFloat($('#new-pay-amount').val());
      var date = $('#new-pay-date').val();
      var method = $('#new-pay-method').val();
      if (isNaN(amount) || amount <= 0) { alert(cigAjax.i18n?.enter_valid_amount || 'Please enter a valid amount (greater than 0).'); return; }
      if (!date) { alert(cigAjax.i18n?.enter_date || 'Please enter a date.'); return; }
      paymentHistory.push({ date: date, amount: amount, method: method, user_id: cigAjax.current_user || 0 });
      $('#new-pay-amount').val('');
      renderPaymentHistory();
  });

  $(document).on('click', '#btn-pay-full', function() {
      if (isReadOnly) return;
      var grandTotal = parseFloat($('#grand-total').text()) || 0;
      var currentPaid = 0;
      paymentHistory.forEach(function(p) { currentPaid += parseFloat(p.amount); });
      var remaining = grandTotal - currentPaid;
      if (remaining <= 0.01) { alert(cigAjax.i18n?.invoice_fully_paid || 'Invoice is already fully paid.'); return; }
      $('#new-pay-amount').val(remaining.toFixed(2));
      $('#new-pay-date').val(cigAjax.site_date);
  });

  $(document).on('click', '.btn-delete-payment', function() {
      if (isReadOnly) return;
      if(!confirm(cigAjax.i18n?.confirm_delete_payment || 'Are you sure you want to delete this record?')) return;
      paymentHistory.splice($(this).data('index'), 1);
      renderPaymentHistory();
  });

  // ---------------------------------------------------------
  // STOCK & AUTOCOMPLETE
  // ---------------------------------------------------------
  function checkStock($row) {
    if (isReadOnly) return;
    var productId = parseInt($row.find('.product-search').attr('data-product-id'), 10);
    var quantity = parseFloat($row.find('.quantity').val()) || 0;
    var $qtyInput = $row.find('.quantity');
    $row.find('.stock-warning').remove(); $qtyInput.removeClass('stock-error');
    if (!productId || quantity <= 0) return;
    if (stockCheckXhr) stockCheckXhr.abort();
    if (stockCheckTimeout) clearTimeout(stockCheckTimeout);
    var $indicator = $('<span class="stock-warning" style="color:#999;font-size:10px;margin-left:5px;">' + (cigAjax.i18n?.checking_stock || 'Checking...') + '</span>');
    $row.find('.col-qty').append($indicator);
    stockCheckTimeout = setTimeout(function () {
      stockCheckXhr = $.ajax({
        url: cigAjax.ajax_url, method: 'POST', dataType: 'json',
        data: { action: 'cig_check_stock', nonce: cigAjax.nonce, product_id: productId, quantity: quantity, invoice_id: invoiceId || 0 },
        success: function (res) {
          $indicator.remove();
          if (res && res.success && res.data) {
            var data = res.data;
            if (!data.can_add) {
              $qtyInput.addClass('stock-error');
              var warning = $('<span class="stock-warning" style="color:#dc3545;font-size:10px;margin-left:5px;font-weight:bold;">' + '⚠ ' + data.message + '</span>');
              $row.find('.col-qty').append(warning);
              if (data.available > 0) { if (confirm(data.message + '\n\nAuto-correct to ' + data.available + '?')) { $qtyInput.val(data.available).trigger('input'); } }
            } else { if (data.stock !== null) $row.find('.col-qty').append('<span class="stock-warning" style="color:#28a745;font-size:10px;margin-left:5px;">✓ ' + data.available + '</span>'); }
          }
        },
        error: function (xhr) { if (xhr.statusText !== 'abort') $indicator.remove(); },
        complete: function () { stockCheckXhr = null; }
      });
    }, 500);
  }

  function initAutocomplete(el) {
    $(el).autocomplete({
      minLength: 1,
      source: function (request, response) { $.ajax({ url: cigAjax.ajax_url, method: 'POST', dataType: 'json', data: { action: 'cig_search_products', nonce: cigAjax.nonce, term: request.term }, success: function (data) { response(data || []); }, error: function () { response([]); } }); },
      select: function (event, ui) {
        var $row = $(this).closest('tr');
        $row.find('.product-search').attr('data-product-id', ui.item.id || 0).attr('data-sku', ui.item.sku || '').val(ui.item.value || '');
        $row.find('.name-sku-value').text(ui.item.sku || '—'); $row.find('.product-brand').val(ui.item.brand || ''); $row.find('.product-desc').val(ui.item.desc || ''); $row.find('.price').val((ui.item.price || 0).toFixed(2));
        $row.attr('data-original-price', ui.item.original_price || ui.item.price || 0);
        if (ui.item.image) { $row.find('.product-image').attr('src', ui.item.image).removeClass('cig-placeholder-img'); } else { $row.find('.product-image').attr('src', cigAjax.placeholder_img).addClass('cig-placeholder-img'); }
        if (ui.item.stock !== null) { $row.find('.product-search').attr('title', 'Stock: ' + ui.item.stock); }
        updateRowTotal($row); checkStock($row); return false;
      }
    });
  }
  initAutocomplete('.product-search');

  $(document).on('click', '#btn-add-row', function () {
    if (isReadOnly) return;
    var $tbody = $('#invoice-items');
    var $new = $tbody.find('tr:last').clone();
    $new.find('input, textarea').val(''); $new.find('.product-search').attr('data-product-id', '0').attr('data-sku', '').removeAttr('title');
    $new.attr('data-original-price', '0'); $new.find('.cig-original-price').hide();
    $new.find('.name-sku-value').text('—'); $new.find('.product-image').attr('src', cigAjax.placeholder_img).addClass('cig-placeholder-img');
    $new.find('.warranty-period').val(''); $new.find('.quantity').val('1').removeClass('stock-error'); $new.find('.price').val('0.00');
    $new.find('.row-total').val('0.00'); 
    
    // Default to 'none' on new row
    $new.find('.product-status').val('none'); 
    
    $new.find('.reservation-days').val(defaultReservationDays).hide(); $new.find('.stock-warning').remove(); $new.find('.btn-remove-row').show();
    $new.find('.col-n').text($tbody.find('tr').length + 1);
    $tbody.append($new); initAutocomplete($new.find('.product-search')); updateGrandTotal();
  });

  $(document).on('click', '.btn-remove-row', function () {
    if (isReadOnly) return;
    if ($('#invoice-items tr').length > 1) { $(this).closest('tr').remove(); $('#invoice-items tr').each(function (i) { $(this).find('.col-n').text(i + 1); }); updateGrandTotal(); }
  });

  $(document).on('click', '.qty-increase', function () { if (isReadOnly) return; var $inp = $(this).closest('.quantity-wrapper').find('.quantity'); $inp.val((parseInt($inp.val(), 10) || 0) + 1).trigger('input'); });
  $(document).on('click', '.qty-decrease', function () { if (isReadOnly) return; var $inp = $(this).closest('.quantity-wrapper').find('.quantity'); var v = parseInt($inp.val(), 10) || 0; if (v > 1) $inp.val(v - 1).trigger('input'); });

  function updateRowTotal($row) {
    var qty = parseFloat($row.find('.quantity').val()) || 0;
    var price = parseFloat($row.find('.price').val()) || 0;
    $row.find('.row-total').val((qty * price).toFixed(2));
    // Show/hide discount indicator
    var origPrice = parseFloat($row.attr('data-original-price')) || 0;
    var $discEl = $row.find('.cig-original-price');
    if (origPrice > 0 && price < origPrice) {
      var pct = Math.round((1 - price / origPrice) * 100);
      if ($discEl.length) {
        $discEl.text(origPrice.toFixed(2) + ' (-' + pct + '%)').show();
      }
    } else {
      if ($discEl.length) $discEl.hide();
    }
    updateGrandTotal();
  }
  function updateGrandTotal() {
    var total = 0;
    var totalDiscount = 0;
    $('#invoice-items tr').each(function () {
      var $row = $(this);
      var status = $row.find('.product-status').val();
      if (status !== 'canceled') {
        total += parseFloat($row.find('.row-total').val()) || 0;
        var origPrice = parseFloat($row.attr('data-original-price')) || 0;
        var price = parseFloat($row.find('.price').val()) || 0;
        var qty = parseFloat($row.find('.quantity').val()) || 0;
        if (origPrice > 0 && price < origPrice) {
          totalDiscount += (origPrice - price) * qty;
        }
      }
    });
    $('#grand-total').text(total.toFixed(2));
    if (totalDiscount > 0.01) {
      $('#cig-discount-summary').text((cigAjax.i18n?.total_discount || 'Discount') + ': ' + totalDiscount.toFixed(2) + ' ₾').show();
    } else {
      $('#cig-discount-summary').hide();
    }
    renderPaymentHistory();
  }
  $(document).on('input', '.quantity', function () { var $row = $(this).closest('tr'); updateRowTotal($row); checkStock($row); });
  $(document).on('input', '.price', function () { updateRowTotal($(this).closest('tr')); });

  /* Prefill Logic */
  function prefillEditData() {
    if (!editMode) return;
    $('#invoice-number').val(cigAjax.invoiceNumber || '');
    
    // Prefill sold date
    if (cigAjax.sold_date) {
        $('#invoice-sold-date').val(cigAjax.sold_date);
    }
    
    var b = cigAjax.buyer || {};
    var $bf = $('.buyer-details');
    var sf = function(sel, val) { if(val) { sel.text(val).removeClass('is-empty'); if(sel.is('li>span')) sel.closest('li').removeClass('is-empty'); }};
    sf($bf.find('strong.editable-field').eq(0), b.name); sf($bf.find('strong.editable-field').eq(1), b.tax_id); sf($bf.find('span.editable-value').eq(0), b.address); sf($bf.find('span.editable-value').eq(1), b.phone); sf($bf.find('span.editable-value').eq(2), b.email);

    var items = cigAjax.items || [];
    if (items.length) {
      var $tbody = $('#invoice-items').empty();
      items.forEach(function (it, i) {
        var resDays = parseInt(it.reservation_days, 10) || defaultReservationDays;
        var st = it.status || 'none'; // Default to none if undefined
        var img = it.image || cigAjax.placeholder_img; var phClass = it.image ? '' : 'cig-placeholder-img';
        var origP = parseFloat(it.original_price || it.price || 0);

        var $row = $('<tr data-original-price="'+origP+'"><td class="col-n">'+(i+1)+'</td>' +
          '<td class="col-name"><input type="text" class="product-search" data-product-id="'+(it.product_id||0)+'" data-sku="'+(it.sku||'')+'" value="'+(it.name||'')+'"><div class="name-sub"><span class="name-sku-label">' + (cigAjax.i18n?.code_label || 'Code:') + '</span> <span class="name-sku-value">'+(it.sku||'—')+'</span></div></td>' +
          '<td class="col-image"><img class="product-image '+phClass+'" src="'+img+'"><select class="warranty-period" style="width:100%;margin-top:5px;font-size:10px;"><option value="">---</option><option value="6m">' + (cigAjax.i18n?.warranty_6m || '6 Months') + '</option><option value="1y">' + (cigAjax.i18n?.warranty_1y || '1 Year') + '</option><option value="2y">' + (cigAjax.i18n?.warranty_2y || '2 Years') + '</option><option value="3y">' + (cigAjax.i18n?.warranty_3y || '3 Years') + '</option></select></td>' +
          '<td class="col-brand"><input type="text" class="product-brand" readonly value="'+(it.brand||'')+'"></td>' +
          '<td class="col-desc"><textarea class="product-desc">'+(it.desc||'')+'</textarea></td>' +
          '<td class="col-qty"><div class="quantity-wrapper"><input type="number" class="quantity" min="1" value="'+(it.qty||1)+'"><div class="qty-btn-group"><button type="button" class="qty-btn qty-increase">▲</button><button type="button" class="qty-btn qty-decrease">▼</button></div></div></td>' +
          '<td class="col-price"><input type="number" class="price" step="0.01" value="'+parseFloat(it.price||0).toFixed(2)+'"><span class="cig-original-price" style="display:none;"></span></td>' +
          '<td class="col-total"><input type="text" class="row-total" readonly value="'+parseFloat(it.total||0).toFixed(2)+'"></td>' +
          '<td class="col-status no-print">' +
            '<select class="product-status">' +
                '<option value="none">---</option>' +
                '<option value="sold">' + (cigAjax.i18n?.sold || 'Sold') + '</option>' +
                '<option value="reserved">' + (cigAjax.i18n?.reserved || 'Reserved') + '</option>' +
                '<option value="canceled">' + (cigAjax.i18n?.canceled || 'Canceled') + '</option>' +
            '</select>' +
            '<input type="number" class="reservation-days" min="1" max="90" value="'+resDays+'" style="width:60px;margin-top:3px;display:'+(st==='reserved'?'block':'none')+'"></td>' +
          '<td class="col-actions no-print"><button type="button" class="btn-remove-row">X</button></td></tr>');
        
        $row.find('.product-status').val(st);
        $row.find('.warranty-period').val(it.warranty || '');
        $tbody.append($row); initAutocomplete($row.find('.product-search'));
        updateRowTotal($row);
      });
      updateGrandTotal();
    }

    if (cigAjax.payment && Array.isArray(cigAjax.payment.history)) { paymentHistory = cigAjax.payment.history; }
    renderPaymentHistory();
    $('#btn-save-invoice').text(cigAjax.i18n?.update_invoice || 'Update Invoice');
  }

  /* Build Payload (AUTO STATUS LOGIC) */
  function buildPayload() {
    saveCurrentEditableField();
    
    // 1. Calculate Total Paid
    var totalPaid = 0;
    paymentHistory.forEach(function(p){ totalPaid += parseFloat(p.amount); });
    
    // 2. Determine Invoice Status
    var invoiceStatus = (totalPaid > 0) ? 'standard' : 'fictive';

    var items = [];
    $('#invoice-items tr').each(function () {
      var $r = $(this); var name = $r.find('.product-search').val().trim(); if (!name) return;
      
      var currentStatus = $r.find('.product-status').val();
      
      // LOGIC: Adjust status based on Invoice Type
      var finalStatus = currentStatus;
      
      if (invoiceStatus === 'fictive') {
          // If invoice is fictive, force status to 'none'
          finalStatus = 'none';
      } else {
          // If invoice is active (standard)
          // If status was 'none' (from fictive state), upgrade to 'reserved' (default)
          if (currentStatus === 'none') {
              finalStatus = 'reserved';
          }
      }

      var resDays = finalStatus === 'reserved' ? parseInt($r.find('.reservation-days').val()) || defaultReservationDays : 0;

      items.push({
        product_id: parseInt($r.find('.product-search').attr('data-product-id')) || 0,
        name: name,
        sku: $r.find('.product-search').attr('data-sku') || '',
        brand: $r.find('.product-brand').val(),
        desc: $r.find('.product-desc').val(),
        image: $r.find('.product-image').attr('src'),
        qty: parseFloat($r.find('.quantity').val()) || 0,
        price: parseFloat($r.find('.price').val()) || 0,
        original_price: parseFloat($r.attr('data-original-price')) || parseFloat($r.find('.price').val()) || 0,
        total: parseFloat($r.find('.row-total').val()) || 0,
        status: finalStatus,
        reservation_days: resDays,
        warranty: $r.find('.warranty-period').val()
      });
    });

    // Helper to get value from editable element by ID
    // Checks both is-empty class and placeholder text comparison
    var getEditableVal = function(selector) {
        var $el = $(selector);
        if (!$el.length) return '';
        
        // Check if element has is-empty class or parent li has is-empty class
        if ($el.hasClass('is-empty') || $el.closest('li').hasClass('is-empty')) {
            return '';
        }
        
        var text = $el.text().trim();
        var placeholder = $el.attr('data-placeholder') || '';
        
        // Return empty if text matches placeholder
        if (placeholder && text === placeholder) {
            return '';
        }
        
        return text;
    };

    var payload = {
        invoice_number: $('#invoice-number').val(),
        buyer: {
            name:    getEditableVal('#buyer-name-display'),
            tax_id:  getEditableVal('#buyer-tax-display'),
            address: getEditableVal('#buyer-address-display'),
            phone:   getEditableVal('#buyer-phone-display'),
            email:   getEditableVal('#buyer-email-display'),
        },
        items: items,
        payment: { history: paymentHistory },
        status: invoiceStatus,
        // NEW: Grab general note
        general_note: $('#invoice-general-note').val(),
        // NEW: Grab sold date (for warranty sheet)
        sold_date: $('#invoice-sold-date').val() || ''
    };
    if (editMode) payload.invoice_id = invoiceId;
    return payload;
  }

  $(document).on('click', '#btn-save-invoice', function () {
    if (isReadOnly) { alert(cigAjax.i18n?.invoice_locked || 'This invoice is locked.'); return; }
    
    var payload = buildPayload();

    // Validation
    var errors = [];
    if (!payload.buyer.name) errors.push(cigAjax.i18n?.buyer_name || 'Buyer Name');
    if (!payload.buyer.tax_id) errors.push(cigAjax.i18n?.tax_id || 'Tax ID');
    if (!payload.buyer.phone) errors.push(cigAjax.i18n?.phone || 'Phone');

    if (errors.length > 0) {
        alert((cigAjax.i18n?.fill_required_fields || 'Please fill in the required fields:') + '\n\n- ' + errors.join('\n- '));
        return;
    }

    if (!payload.items.length) { alert(cigAjax.i18n.empty_items); return; }
    
    if (payload.status === 'standard' && $('.stock-error').length > 0 && !confirm(cigAjax.i18n?.stock_warnings_proceed || 'Stock warnings present. Proceed?')) return;

    var $btn = $(this).prop('disabled', true).text(cigAjax.i18n?.saving || 'Saving...');
    var action = editMode ? 'cig_update_invoice' : 'cig_save_invoice';

    $.post(cigAjax.ajax_url, { action: action, nonce: cigAjax.nonce, payload: JSON.stringify(payload) }, function(res) {
        if (res.success) {
            // Clear DB Cart after successful save
            $.post(cigAjax.ajax_url, {
                action: 'cig_clear_cart_db',
                nonce: cigAjax.nonce
            });
            alert(editMode ? (cigAjax.i18n?.updated_successfully || 'Updated successfully.') : (cigAjax.i18n?.saved_successfully || 'Saved successfully.'));
            window.location.href = res.data.view_url;
        } else {
            alert('Error: ' + (res.data.message || cigAjax.i18n?.save_failed || 'Save failed'));
            $btn.prop('disabled', false).text(editMode ? (cigAjax.i18n?.update_invoice || 'Update Invoice') : (cigAjax.i18n?.save_invoice || 'Save Invoice'));
        }
    }).fail(function() { alert(cigAjax.i18n?.server_error || 'Server Error'); $btn.prop('disabled', false).text(editMode ? (cigAjax.i18n?.update_invoice || 'Update Invoice') : (cigAjax.i18n?.save_invoice || 'Save Invoice')); });
  });
  
  $(document).on('click', '#cig-mark-sold', function(e) {
      e.preventDefault();
      if(!confirm(cigAjax.i18n?.confirm_mark_sold || 'Are you sure you want to mark reserved products as sold?')) return;
      var $btn = $(this); $btn.prop('disabled', true).text(cigAjax.i18n?.processing || 'Processing...');
      $.ajax({
          url: cigAjax.ajax_url, method: 'POST',
          data: { action: 'cig_mark_as_sold', nonce: cigAjax.nonce, invoice_id: invoiceId },
          success: function(res) {
              if(res.success) { alert(res.data.message); location.reload(); } 
              else { alert(res.data.message); $btn.prop('disabled', false).text(cigAjax.i18n?.mark_as_sold || 'Mark as Sold'); }
          },
          error: function() { alert(cigAjax.i18n?.connection_error || 'Connection error'); $btn.prop('disabled', false).text(cigAjax.i18n?.mark_as_sold || 'Mark as Sold'); }
      });
  });

});