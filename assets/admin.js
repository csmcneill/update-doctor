(function () {
	'use strict';

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			try {
				document.execCommand('copy');
				resolve();
			} catch (err) {
				reject(err);
			} finally {
				document.body.removeChild(ta);
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var btn = document.getElementById('update-doctor-copy-report');
		if (!btn) {
			return;
		}

		btn.addEventListener('click', function () {
			var report = btn.getAttribute('data-report') || '';
			var original = btn.textContent;
			copyText(report).then(function () {
				btn.textContent = 'Copied!';
				setTimeout(function () {
					btn.textContent = original;
				}, 2000);
			}, function () {
				btn.textContent = 'Copy failed';
				setTimeout(function () {
					btn.textContent = original;
				}, 2000);
			});
		});
	});
})();
