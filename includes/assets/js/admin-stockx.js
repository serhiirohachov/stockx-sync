jQuery(document).ready(function($) {
  $('.sync-stockx-price').on('click', function() {
      const $btn = $(this);
      const variation_id = $btn.data('variation-id');
      const $result = $btn.next('.stockx-sync-price-result');

      $btn.prop('disabled', true).text('⏳ Syncing...');

      $.post(ajaxurl, {
          action: 'stockx_sync_variation_price',
          variation_id: variation_id
      }).done(function(response) {
          if (response.success) {
              $result.text(`💲 ${response.data.price}`);
              $btn.text('✅ Synced');
          } else {
              $result.text(`❌ ${response.data?.message || 'Error'}`);
              $btn.text('Retry');
          }
      }).fail(function() {
          $result.text('❌ AJAX error');
          $btn.text('Retry');
      }).always(function() {
          $btn.prop('disabled', false);
      });
  });
});

jQuery(function($){
    // Універсальний делегований хендлер для обох кнопок
// Делегуємо клік по всіх кнопках .stockx-fetch-btn
  $(document).on('click', '.stockx-fetch-btn', function(e){
    e.preventDefault();

    const btn       = $(this);
    const action    = btn.data('stockx-action');
    const postId    = btn.data('post-id') || (window.StockXAdmin && StockXAdmin.post_id);
    const origText  = btn.text();
    let   resultBox = btn.next('.stockx-fetch-result');

    if (!action || !postId) {
      console.error('stockx-fetch-btn missing data attributes');
      return;
    }

    // Створюємо контейнер під повідомлення, якщо ще нема
    if (!resultBox.length) {
      resultBox = $('<div class="stockx-fetch-result" style="margin-top:5px;"></div>');
      btn.after(resultBox);
    }
    resultBox.empty();

    // Блокуємо кнопку
    btn.prop('disabled', true).text('Fetching…');

    // Робимо AJAX
    $.post( StockXAdmin.ajax_url, {
      action:    action,
      product_id: postId,
      variation_id: btn.data('variation-id') // якщо знадобиться для variation-запитів
    }, function(response){
      // Відновлюємо кнопку
      btn.prop('disabled', false).text(origText);

      if (response.success) {
        // Якщо це фетч URL — оновлюємо поле
        if (action === 'stockx_fetch_url' || action === 'stockx_get_url_single') {
          $('#stockx_base_url').val(response.data);
        }
        // Можна за потреби оновити інші поля або повідомити про кількість синхронізованих варіацій
        resultBox.html('<span style="color:green;">Done</span>');
      } else {
        const msg = response.data?.message || response.data || 'Error';
        resultBox.html('<span style="color:red;">' + msg + '</span>');
      }
    }, 'json')
    .fail(function(jqXHR, textStatus){
      btn.prop('disabled', false).text(origText);
      resultBox.html('<span style="color:red;">AJAX error: ' + textStatus + '</span>');
    });
  });
    $('#get_stockx_url_single').on('click', function(e){
      e.preventDefault();
  
      var btn    = $(this),
          orig   = btn.text(),
          postId = typeof StockXAdmin !== 'undefined'
                 ? StockXAdmin.post_id
                 : btn.data('post_id');
  
      if (! postId) {
        return console.error('No post ID for StockX fetch');
      }
  
      // Блокуємо кнопку
      btn.prop('disabled', true).text('Fetching…');
  
      // Відправляємо AJAX
      $.post( StockXAdmin.ajax_url, {
        action:     'stockx_get_url_single',
        product_id: postId
      }, function(response){
        // відновлюємо кнопку
        btn.prop('disabled', false).text(orig);
  
        if ( response.success ) {
          // Оновлюємо поле StockX Base URL
          $('#stockx_base_url').val( response.data );
        } else {
          // Можеш замінити на повідомлення біля кнопки
          alert('Error: ' + (response.data.message || response.data));
        }
      }, 'json' )
      .fail(function(){
        btn.prop('disabled', false).text(orig);
        alert('AJAX error');
      });
    });
    
    
  });
  
  jQuery(function($){
    // Save Base URL button
    $('.stockx-save-base-url').on('click', function(){
        var url = $('#stockx_base_url').val();
        var data = { action: 'stockx_save_base_url', product_id: <?php echo (int) $post->ID; ?>, url: url };
        $.post(ajaxurl, data, function(res){
            alert(res.success ? '<?php _e('Base URL saved', 'stockx-sync'); ?>' : '<?php _e('Error saving Base URL', 'stockx-sync'); ?>');
        });
    });

    $('#get_stockx_url_single').on('click', function(){
        $.post(ajaxurl, { action: 'stockx_get_url_single', product_id: <?php echo (int) $post->ID; ?> }, function(response){
            alert(response.success ? '<?php _e('URL fetched: ', 'stockx-sync'); ?>'+response.data : '<?php _e('Error: ', 'stockx-sync'); ?>'+response.data);
            location.reload();
        });
    });    });

    jQuery(function($){
      $('#stockx_sync_prices_all_delayed').on('click', function(){
          var btn = $(this);
          btn.prop('disabled', true).text('<?php _e('Syncing prices...', 'stockx-sync'); ?>');

          // gather all variation IDs and sizes
          var variations = [];
          $('.stockx_manual_url').each(function(){
              var vid = $(this).data('id');
              var size = $(this).data('size') || $(this).val();
              variations.push({ id: vid, size: size });
          });
          var total = variations.length;

          // setup progress UI
          $('#stockx_progress_bar').attr('max', total).val(0);
          $('#stockx_progress_text').text('0/' + total);
          $('#stockx_progress_list').empty();
          $('#stockx_progress_container, #stockx_progress_details').show();

          var delay = 10000; // 10s between calls
          variations.forEach(function(varObj, index) {
              setTimeout(function(){
                  console.debug('Starting sync for var', varObj);
                  $.post(ajaxurl, { action: 'stockx_sync_variation_price', variation_id: varObj.id }, function(response){
                      var msg = response.success ? '✅ ' + response.data : '❌ ' + (response.data||response.message);
                      // update status next to input
                      $('.stockx_manual_url[data-id="'+varObj.id+'"]').siblings('.stockx-sync-status').text(msg);

                      // update progress bar and list
                      var current = $('#stockx_progress_bar').val() * 1 + 1;
                      $('#stockx_progress_bar').val(current);
                      $('#stockx_progress_text').text(current + '/' + total);
                      $('#stockx_progress_list').append(
                          '<li>('+ varObj.size +') ' + msg + '</li>'
                      );
                      console.debug('Finished sync for var', varObj, msg);

                      // when done
                      if(current === total) {
                          btn.prop('disabled', false).text('<?php _e('Sync Prices All (with Delay)', 'stockx-sync'); ?>');
                          console.debug('All variations synced');
                      }
                  });
              }, index * delay);
          });
      });
  });

  