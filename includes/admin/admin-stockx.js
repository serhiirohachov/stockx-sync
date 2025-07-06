jQuery(function($){
  const ajaxUrl = window.StockXAdmin.ajax_url;
  const postId  = window.StockXAdmin.post_id;

  $(document).on('click', '.stockx-fetch-btn', function(e){
    e.preventDefault();

    const btn      = $(this);
    const action   = btn.data('stockx-action');           // e.g. stockx_save_base_url, stockx_sync_variation_url, stockx_sync_variation_price, stockx_save_variation_price_manual
    const vid      = btn.data('variation-id') || 0;        // для варіацій
    const origText = btn.data('orig-text') || btn.text();
    let   resultBox= btn.next('.stockx-fetch-result');

    if (!action) {
      console.error('Missing data-stockx-action');
      return;
    }

    // Створюємо або очищуємо зону для результату
    if (!resultBox.length) {
      resultBox = $('<div class="stockx-fetch-result" style="margin:5px 0;"></div>');
      btn.after(resultBox);
    }
    resultBox.empty();

    // Блокуємо кнопку
    btn.prop('disabled', true).text('⏳ Working…');

    // Формуємо payload
    const data = { action: action };
    if (vid) {
      data.variation_id = vid;
    } else {
      data.product_id = postId;
    }

    // для Save Base URL
    if (action === 'stockx_save_base_url') {
      data.url      = $('#stockx_base_url').val();
      data.is_women = $('#stockx_women_flag').is(':checked') ? 'true' : 'false';
    }

    // для Manual Save Price
    if (action === 'stockx_save_variation_price_manual') {
      const priceInput = btn.siblings('.stockx-price-input');
      data.price = priceInput.val();
    }

    // AJAX-запит
    $.post(ajaxUrl, data, function(response){
      // відновлюємо кнопку
      btn.prop('disabled', false).text(origText);

      if (response.success) {
        // оновлюємо Base URL у полі
        if (action === 'stockx_save_base_url' || action === 'stockx_get_url_single') {
          $('#stockx_base_url').val(response.data.url || response.data);
        }
        // синхронізуємо інпут ціни після manual save
        if (action === 'stockx_save_variation_price_manual') {
          btn.siblings('.stockx-price-input').val(response.data.price);
        }
        resultBox.html('<span style="color:green;">✅ Done</span>');
      } else {
        const msg = response.data?.message || response.data || 'Error';
        resultBox.html('<span style="color:red;">❌ ' + msg + '</span>');
      }
    }, 'json').fail(function(_, textStatus){
      btn.prop('disabled', false).text(origText);
      resultBox.html('<span style="color:red;">AJAX error: ' + textStatus + '</span>');
    });
  });
});

jQuery(function($){
  // збереження вручну введеної URL-ки
  $(document).on('click', '.stockx-save-manual-url', function(e){
    e.preventDefault();
    const btn = $(this);
    // замість btn.data('id')
    const vid = btn.data('variation-id');
    const input = btn.siblings('.stockx_manual_url');
    const url = input.val();
    $.post( ajaxUrl, {
      action:       'stockx_save_manual_url',
      variation_id: vid,
      url:          url
    }, function(response){
      btn.prop('disabled', false).text('Save URL');
      if ( response.success ) {
        // коротке повідомлення про успіх
        btn.after('<span class="stockx-save-success" style="color:green; margin-left:8px;">Saved</span>');
        setTimeout(function(){
          btn.siblings('.stockx-save-success').fadeOut(200, function(){ $(this).remove(); });
        }, 2000);
      } else {
        alert( 'Error: ' + (response.data || 'unknown') );
      }
    }, 'json').fail(function(){
      btn.prop('disabled', false).text('Save URL');
      alert('AJAX error');
    });
  });
});
а

