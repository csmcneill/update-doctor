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

	function wireCopyButton() {
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
	}

	function wireClearLockModal() {
		var trigger = document.getElementById('update-doctor-clear-lock-button');
		var modal = document.getElementById('update-doctor-clear-lock-modal');
		var form = document.getElementById('update-doctor-clear-lock-form');
		if (!trigger || !modal || !form) {
			return;
		}

		var cancel = document.getElementById('update-doctor-modal-cancel');
		var confirm = document.getElementById('update-doctor-modal-confirm');

		function open() {
			modal.hidden = false;
			if (confirm) {
				confirm.focus();
			}
		}
		function close() {
			modal.hidden = true;
			trigger.focus();
		}

		trigger.addEventListener('click', open);
		if (cancel) {
			cancel.addEventListener('click', close);
		}
		if (confirm) {
			confirm.addEventListener('click', function () {
				form.submit();
			});
		}
		// Click outside the dialog, or Escape, cancels.
		modal.addEventListener('click', function (e) {
			if (e.target === modal) {
				close();
			}
		});
		document.addEventListener('keydown', function (e) {
			if (!modal.hidden && (e.key === 'Escape' || e.keyCode === 27)) {
				close();
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		wireCopyButton();
		wireClearLockModal();
	});
})();
