jQuery(document).ready(function($) {
  var $root = $('.sync-mautic-settings');

  if (!$root.length) {
    return;
  }

  initTabs();
  initPluginSlider();
  initSupport();
  initOrderTracking();
  initGeneral();

  function initTabs() {
    function tabHash($tab) {
      return $tab.attr('id').replace('sync-mautic-tab-', '');
    }

    function showTab($tab, updateHash) {
      var target = $tab.data('tab');
      var hash = tabHash($tab);

      $root.find('.sync-mautic-nav__tab').removeClass('is-active').attr('aria-selected', 'false');
      $tab.addClass('is-active').attr('aria-selected', 'true');

      $root.find('.sync-mautic-panel').removeClass('is-active').attr('hidden', 'hidden');
      $root.find(target).addClass('is-active').removeAttr('hidden');

      $root.find('.sync-mautic-settings__title').text($.trim($tab.find('.sync-mautic-nav__label').text()));
      $root.find('.sync-mautic-settings__summary').text($tab.attr('data-description'));

      if (!updateHash || !window.history || !window.history.replaceState) {
        return;
      }

      window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + hash);
    }

    function tabFromHash() {
      var hash = window.location.hash.replace('#', '');

      if (!/^[a-z0-9-]+$/i.test(hash)) {
        return $();
      }

      return $root.find('#sync-mautic-tab-' + hash);
    }

    $root.on('click', '.sync-mautic-nav__tab', function(e) {
      e.preventDefault();
      showTab($(this), true);
    });

    $(window).on('hashchange.syncMauticTabs', function() {
      var $tab = tabFromHash();

      if ($tab.length) {
        showTab($tab, false);
      }
    });

    var $initial = tabFromHash();

    if ($initial.length) {
      showTab($initial, false);
    }
  }

  function initSupport() {
    var prompt = $root.find('.sync-mautic-review-prompt').get(0);
    var promptTrigger = null;

    $root.on('click', '.sync-mautic-support__star', function(e) {
      var rating = parseInt(this.getAttribute('data-rating'), 10);

      if (!prompt || rating > 3) {
        return;
      }

      e.preventDefault();
      promptTrigger = this;

      if (typeof prompt.showModal === 'function' && !prompt.open) {
        prompt.showModal();
      }
    });

    if (prompt) {
      $(prompt).on('click', function(e) {
        if (e.target === prompt) {
          prompt.close();
        }
      });

      $(prompt).on('click', '.sync-mautic-review-prompt__close, .sync-mautic-review-prompt__reach', function() {
        window.setTimeout(function() {
          if (prompt.open) {
            prompt.close();
          }
        }, 0);
      });

      $(prompt).on('close', function() {
        if (promptTrigger) {
          promptTrigger.focus();
          promptTrigger = null;
        }
      });
    }

    $root.on('click', '.sync-mautic-support__copy', function() {
      var button = this;
      var text = button.getAttribute('data-copy');
      var $label = $(button).find('.sync-mautic-support__copy-label');
      var original = $label.text();

      function copied() {
        $label.text(button.getAttribute('data-copied'));
        window.setTimeout(function() {
          $label.text(original);
        }, 2000);
      }

      function fallbackCopy() {
        var area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        document.body.removeChild(area);
        copied();
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(copied).catch(fallbackCopy);
        return;
      }

      fallbackCopy();
    });
  }

  function initPluginSlider() {
    var $slider = $root.find('.sync-mautic-plugins');
    var $track = $slider.find('.sync-mautic-plugins__track');
    var $viewport = $slider.find('.sync-mautic-plugins__viewport');
    var $dots = $slider.find('.sync-mautic-plugins__dot');
    var count = $dots.length;
    var position = 1;
    var timer = null;
    var settleTimer = null;
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!$slider.length || count < 2) {
      return;
    }

    var $slides = $track.children('.sync-mautic-plugins__slide');

    $track.prepend($slides.last().clone().attr('aria-hidden', 'true'));
    $track.append($slides.first().clone().attr('aria-hidden', 'true'));

    function showSlide(next, animate) {
      var width = $viewport.innerWidth();

      if (!width) {
        return;
      }

      if (reduceMotion) {
        if (next < 1) {
          next = count;
        } else if (next > count) {
          next = 1;
        }

        animate = false;
      } else if (next > count + 1) {
        next = ((next - 1) % count) + 1;
      } else if (next < 0) {
        next = count;
      }

      // Resize and dragging cancel the animation, so transitionend never fires. Do not stop on a clone.
      if (animate === false && (next === 0 || next === count + 1)) {
        next = next === 0 ? count : 1;
      }

      position = next;
      clearTimeout(settleTimer);

      if (animate === false) {
        $track.css('transition', 'none');
      }

      $track.css('transform', 'translateX(' + (position * -width) + 'px)');

      if (animate === false) {
        $track[0].offsetHeight;
        $track.css('transition', '');
      }

      var dot = position - 1;

      if (position === 0) {
        dot = count - 1;
      } else if (position > count) {
        dot = 0;
      }

      $dots.removeClass('is-active').attr('aria-selected', 'false');
      $dots.eq(dot).addClass('is-active').attr('aria-selected', 'true');

      if (position === 0 || position === count + 1) {
        settleTimer = setTimeout(finishLoop, 400);
      }
    }

    function finishLoop() {
      if (position === 0) {
        showSlide(count, false);
      } else if (position === count + 1) {
        showSlide(1, false);
      }
    }

    $track.on('transitionend', function(e) {
      if (e.target !== $track[0] || e.originalEvent.propertyName !== 'transform') {
        return;
      }

      finishLoop();
    });

    function queue() {
      if (reduceMotion) {
        return;
      }

      clearInterval(timer);
      timer = setInterval(function() {
        if (position === 0 || position === count + 1) {
          showSlide(position === 0 ? count : 1, false);
        }

        showSlide(position + 1);
      }, 6000);
    }

    showSlide(1, false);

    $(window).on('resize.syncMauticPlugins', function() {
      showSlide(position, false);
    });

    $slider.on('click', '.sync-mautic-plugins__dot', function() {
      showSlide($(this).index() + 1);
      queue();
    });

    $slider.on('mouseenter', function() {
      clearInterval(timer);
    });

    $slider.on('mouseleave', queue);

    var dragging = false;
    var startX = 0;
    var deltaX = 0;
    var width = 0;
    var pointerId = null;
    var suppressClick = false;

    $viewport.on('pointerdown', function(e) {
      if (e.pointerType === 'mouse' && e.button !== 0) {
        return;
      }

      if ($(e.target).closest('a, button').length) {
        return;
      }

      dragging = true;
      startX = e.clientX;
      deltaX = 0;
      width = $viewport.outerWidth();
      pointerId = e.pointerId;
      clearInterval(timer);
      $viewport.addClass('is-dragging');
      $track.css('transition', 'none');

      if ($viewport[0].setPointerCapture) {
        try {
          $viewport[0].setPointerCapture(e.pointerId);
        } catch (err) {
          // Some pointer events cannot be captured. Dragging still follows the move events.
        }
      }
    });

    $viewport.on('pointermove', function(e) {
      var offset = 0;

      if (!dragging || e.pointerId !== pointerId) {
        return;
      }

      deltaX = e.clientX - startX;
      offset = deltaX;

      $track.css('transform', 'translateX(' + ((position * -$viewport.innerWidth()) + offset) + 'px)');
    });

    $viewport.on('pointerup pointercancel', function(e) {
      var threshold = 0;

      if (!dragging || e.pointerId !== pointerId) {
        return;
      }

      dragging = false;
      pointerId = null;
      $viewport.removeClass('is-dragging');
      $track.css('transition', '');
      $track[0].offsetHeight;
      threshold = Math.max(40, width * 0.15);

      if (deltaX <= -threshold) {
        showSlide(position + 1);
      } else if (deltaX >= threshold) {
        showSlide(position - 1);
      } else {
        showSlide(position);
      }

      if (Math.abs(deltaX) > 8) {
        suppressClick = true;
      }

      if (e.pointerType !== 'mouse') {
        queue();
      }
    });

    $viewport.on('click', 'a, button', function(e) {
      if (!suppressClick) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();
      suppressClick = false;
    });

    $viewport.on('dragstart', function(e) {
      e.preventDefault();
    });

    queue();
  }

  function initOrderTracking() {
    var enabled = document.getElementById('order_tracking');
    var pageHits = document.getElementById('order_tracking_method_page_hits');
    var webhook = document.getElementById('order_tracking_method_webhook');
    var options = document.getElementById('sync-mautic-order-tracking-options');
    var secretRow = document.getElementById('sync-mautic-webhook-options');

    function syncMauticToggleOrderTracking() {
      if (!enabled || !webhook || !options || !secretRow) {
        return;
      }

      options.classList.toggle('is-disabled', !enabled.checked);
      secretRow.classList.toggle('is-hidden', !webhook.checked);
    }

    if (enabled) {
      enabled.addEventListener('change', syncMauticToggleOrderTracking);
    }

    if (pageHits) {
      pageHits.addEventListener('change', syncMauticToggleOrderTracking);
    }

    if (webhook) {
      webhook.addEventListener('change', syncMauticToggleOrderTracking);
    }

    syncMauticToggleOrderTracking();

    function syncMauticCheckoutPreview() {
      var preview = document.getElementById('sync-mautic-optin-preview');
      var selected = document.querySelector('#checkout_checkbox input:checked');

      if (!preview || !selected) {
        return;
      }

      preview.checked = selected.value === 'checked';
    }

    $root.on('change', '#checkout_checkbox input', syncMauticCheckoutPreview);
    syncMauticCheckoutPreview();
  }

  function initGeneral() {
    $root.on('click', '.sync-mautic-secret__toggle', function() {
      var $button = $(this);
      var $input = $button.siblings('input');
      var show = $input.attr('type') === 'password';

      $input.attr('type', show ? 'text' : 'password');
      $button.attr('aria-pressed', show ? 'true' : 'false');
      $button.attr('aria-label', show ? 'Hide client secret' : 'Show client secret');
      $button.find('.dashicons').toggleClass('dashicons-visibility', !show).toggleClass('dashicons-hidden', show);
    });

    $root.on('click', '.sync-mautic-redirect__copy', function() {
      var button = this;
      var text = button.getAttribute('data-copy');

      function copied() {
        button.classList.add('is-copied');
        window.setTimeout(function() {
          button.classList.remove('is-copied');
        }, 2000);
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(copied);
        return;
      }

      var area = document.createElement('textarea');
      area.value = text;
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      document.body.removeChild(area);
      copied();
    });

    $root.on('click', '.sync-mautic-test', function(e) {
      var $button = $(this);
      var $status = $root.find('.sync-mautic-connection__status');
      var $result = $root.find('.sync-mautic-test__result');
      var $label = $button.find('.sync-mautic-test__label');

      e.preventDefault();

      if ($button.prop('disabled')) {
        return;
      }

      $button.prop('disabled', true);
      $label.text('Testing...');
      $result.text('');

      $.post(ajaxurl, {
        action: 'sync_mautic_test_connection',
        nonce: $button.attr('data-nonce'),
        base_url: $('#sync-mautic-base-url').val(),
        client_id: $('#sync-mautic-client-id').val(),
        client_secret: $('#sync-mautic-client-secret').val()
      }).done(function(response) {
        var connected = !!(response && response.success);
        var message = response && response.data && response.data.message ? response.data.message : '';

        $status.toggleClass('is-connected', connected).text(connected ? 'Connected' : 'Not connected');
        $result.text(connected ? '' : message);
      }).fail(function(xhr) {
        var message = 'Could not reach WordPress to test the connection.';

        if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        }

        $status.removeClass('is-connected').text('Not connected');
        $result.text(message);
      }).always(function() {
        $button.prop('disabled', false);
        $label.text('Test Connection');
      });
    });
  }
});
