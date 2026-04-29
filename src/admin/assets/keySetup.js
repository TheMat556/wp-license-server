/**
 * Key setup admin notice: CSP-safe copy-to-clipboard button.
 *
 * When the admin clicks "Copy Key to Clipboard", this fetches the encryption
 * key from the REST API and writes it to the clipboard. No key data is ever
 * embedded in the DOM.
 *
 * @package WpLicenseServer
 */
/* global document, navigator, fetch, setTimeout, wplicenseKeySetup */

(function () {
  'use strict';

  var button = document.getElementById('wplicense-copy-key');
  if (!button) return;

  button.addEventListener('click', function (e) {
    e.preventDefault();
    button.textContent = 'Fetching…';
    button.disabled = true;

    fetch(wplicenseKeySetup.restUrl, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': wplicenseKeySetup.nonce,
      },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('Request failed (' + res.status + ')');
        return res.json();
      })
      .then(function (data) {
        return navigator.clipboard.writeText(data.key);
      })
      .then(function () {
        button.textContent = 'Copied!';
        setTimeout(function () {
          button.textContent = 'Copy Key to Clipboard';
          button.disabled = false;
        }, 3000);
      })
      .catch(function () {
        button.textContent = 'Failed — check console';
        button.disabled = false;
      });
  });
})();
