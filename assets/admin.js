(function($){
  $(function(){
    function nextIndex() {
      return $('#pifx-meta-rows tr').length;
    }
    function addMetaRow(mk, xp) {
      var idx = nextIndex();
      var row = '<tr><td><input list="pifx-meta-keys" type="text" name="meta_mappings['+idx+'][meta_key]" value="'+(mk||'')+'" /></td><td><input type="text" name="meta_mappings['+idx+'][xml_path]" value="'+(xp||'')+'" class="regular-text" /></td></tr>';
      $('#pifx-meta-rows').append(row);
    }

    $('#pifx-add-meta-row').on('click', function(e){
      e.preventDefault();
      addMetaRow('', '');
    });

    $('#pifx-autodetect').on('click', function(e){
      e.preventDefault();
      var $btn = $(this);
      $btn.prop('disabled', true).text('Detecting...');
      var feed = $('input[name="feed_url"]').val();
      $.post(PIFX.ajax, {
        action: 'pifx_autodetect_mapping',
        nonce: PIFX.nonce,
        feed_url: feed
      }).done(function(resp){
        if (resp && resp.success) {
          if (resp.data.item_path) {
            $('input[name="item_path"]').val(resp.data.item_path);
          }
          var m = resp.data.mappings || {};
          Object.keys(m).forEach(function(k){
            $('input[name="mappings['+k+']"]').val(m[k]);
          });
          var mm = resp.data.meta_mappings || {};
          Object.keys(mm).forEach(function(metaKey){
            var applied = false;
            $('#pifx-meta-rows tr').each(function(){
              var mkInput = $(this).find('input[name^="meta_mappings"][name$="[meta_key]"]');
              var xpInput = $(this).find('input[name^="meta_mappings"][name$="[xml_path]"]');
              if (mkInput.length && xpInput.length && mkInput.val() === metaKey) {
                xpInput.val(mm[metaKey]);
                applied = true;
                return false;
              }
            });
            if (!applied) {
              addMetaRow(metaKey, mm[metaKey]);
            }
          });
          alert('Auto-detected mapping applied. Please review and save.');
        } else {
          alert('Auto-detect failed: ' + (resp && resp.data ? resp.data : 'Unknown error'));
        }
      }).fail(function(xhr){
        alert('Auto-detect error: ' + xhr.statusText);
      }).always(function(){
        $btn.prop('disabled', false).text('Auto-detect from XML');
      });
    });
  });
})(jQuery);