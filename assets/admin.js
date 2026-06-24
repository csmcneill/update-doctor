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

	function flashLabel(btn, msg) {
		var original = btn.textContent;
		btn.textContent = msg;
		setTimeout(function () {
			btn.textContent = original;
		}, 2000);
	}

	// Any [data-copy] button copies its attribute value; the legacy report button
	// uses [data-report].
	function wireCopyButtons() {
		document.querySelectorAll('[data-copy], #update-doctor-copy-report').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var text = btn.getAttribute('data-copy') || btn.getAttribute('data-report') || '';
				copyText(text).then(
					function () { flashLabel(btn, 'Copied!'); },
					function () { flashLabel(btn, 'Copy failed'); }
				);
			});
		});
	}

	// Generic modal framework: [data-modal-target] opens, [data-modal-close] closes,
	// plus overlay-click and Escape. Used by both the Clear Lock confirm and the
	// WP-CLI command modals.
	function wireModals() {
		var openModal = null;

		function open(modal) {
			modal.hidden = false;
			openModal = modal;
			var focusable = modal.querySelector('button');
			if (focusable) {
				focusable.focus();
			}
		}
		function close(modal) {
			if (!modal) {
				return;
			}
			modal.hidden = true;
			if (openModal === modal) {
				openModal = null;
			}
		}

		document.querySelectorAll('[data-modal-target]').forEach(function (trigger) {
			trigger.addEventListener('click', function () {
				var modal = document.querySelector(trigger.getAttribute('data-modal-target'));
				if (modal) {
					open(modal);
				}
			});
		});

		document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				close(btn.closest('.update-doctor-modal-overlay'));
			});
		});

		// Click on the overlay backdrop (not the dialog) closes it.
		document.querySelectorAll('.update-doctor-modal-overlay').forEach(function (overlay) {
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					close(overlay);
				}
			});
		});

		document.addEventListener('keydown', function (e) {
			if (openModal && (e.key === 'Escape' || e.keyCode === 27)) {
				close(openModal);
			}
		});

		// Clear Lock confirm: submit the associated form.
		var confirm = document.getElementById('update-doctor-modal-confirm');
		var clearForm = document.getElementById('update-doctor-clear-lock-form');
		if (confirm && clearForm) {
			confirm.addEventListener('click', function () {
				clearForm.submit();
			});
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		wireCopyButtons();
		wireModals();
	});
})();
