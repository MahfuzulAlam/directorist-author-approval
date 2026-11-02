(function () {
	'use strict';

	var storageKey = 'daa-author-registration';
	var forms = Array.prototype.map.call(document.querySelectorAll('input[name="daa_request_author"]'), function (input) {
		return input.closest('form');
	}).filter(Boolean);

	function remember(form) {
		var requested = form.querySelector('input[name="daa_request_author"]:checked');
		try {
			if (requested) {
				sessionStorage.setItem(storageKey, JSON.stringify({ time: Date.now(), page: location.href }));
			} else {
				sessionStorage.removeItem(storageKey);
			}
		} catch (error) {
			// Registration still works when storage is unavailable.
		}
	}

	forms.forEach(function (form) {
		form.classList.add('daa-registration-form');
		var author = form.querySelector('input[name="user_type"][value="author"]');
		var regular = form.querySelector('input[name="user_type"][value="general"]');
		var request = form.querySelector('input[name="daa_request_author"]');
		if (author || regular) {
			form.classList.add('daa-registration-native-type');
			if (!form.querySelector('input[name="user_type"]:checked')) {
				(regular || author).checked = true;
			}
			var syncRequest = function () { request.checked = !!author && author.checked; };
			form.querySelectorAll('input[name="user_type"]').forEach(function (radio) {
				radio.addEventListener('change', syncRequest);
			});
			syncRequest();
			var option = request.closest('.daa-author-option');
			var lastType = (regular || author).closest('.atbd_user_type_area');
			if (option && lastType) {
				lastType.insertAdjacentElement('afterend', option);
			}
		}
		form.addEventListener('submit', function () { remember(form); });
		form.querySelectorAll('.directorist-authentication__form__btn').forEach(function (button) {
			button.addEventListener('click', function () { remember(form); });
		});
	});

	var saved;
	try {
		saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
	} catch (error) {
		saved = null;
	}
	if (!saved || Date.now() - saved.time > 10 * 60 * 1000) {
		return;
	}

	var url = new URL(location.href);
	var pending = url.searchParams.has('daa_author_request') || url.searchParams.has('registration_status');
	var verifying = url.searchParams.has('verification');
	if (!pending && !verifying && saved.page === location.href) {
		return;
	}
	if (!pending && !verifying && forms.length) {
		return;
	}
	if (pending && document.querySelector('.daa-registration-confirmation')) {
		sessionStorage.removeItem(storageKey);
		return;
	}

	var notice = document.createElement('div');
	notice.className = 'daa-registration-confirmation';
	notice.setAttribute('role', 'status');
	notice.textContent = verifying ? daaAuthorApproval.verifyMessage : daaAuthorApproval.pendingMessage;
	var container = document.querySelector('.directorist-registration-wrapper, .directorist-account-block-register-form, main') || document.body;
	container.insertBefore(notice, container.firstChild);
	sessionStorage.removeItem(storageKey);
}());
