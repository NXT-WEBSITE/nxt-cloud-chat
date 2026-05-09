/**
 * Handles expand/collapse behavior for the floating support badge.
 *
 * @package NXTCC
 */

jQuery(function ($) {
	const $badge = $('#nxtcc-support-badge');
	if (0 === $badge.length) {
		return;
	}

	const $link = $badge.find('.nxtcc-support-badge__link');
	const $toggle = $badge.find('.nxtcc-support-badge__toggle');
	const collapsedClass = 'is-collapsed';
	const storageKey = String($badge.data('storageKey') || 'nxtccSupportBadgeCollapsed');
	const collapsedLabel = String($badge.data('collapsedLabel') || 'Get Support');

	function persist(collapsed) {
		try {
			window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
		} catch (error) {
			// Ignore storage failures and continue with the in-memory UI state.
		}
	}

	function setCollapsed(collapsed) {
		$badge.toggleClass(collapsedClass, Boolean(collapsed));
		$toggle.attr('aria-expanded', collapsed ? 'false' : 'true');

		if (collapsed) {
			$link.attr('aria-label', collapsedLabel);
		} else {
			$link.removeAttr('aria-label');
		}

		persist(Boolean(collapsed));
	}

	try {
		if ('1' === window.localStorage.getItem(storageKey)) {
			setCollapsed(true);
		}
	} catch (error) {
		// Ignore storage read failures.
	}

	$toggle.on('click', function (event) {
		event.preventDefault();
		event.stopPropagation();
		setCollapsed(!$badge.hasClass(collapsedClass));
	});

	$link.on('click', function (event) {
		if ($badge.hasClass(collapsedClass)) {
			event.preventDefault();
			setCollapsed(false);
		}
	});
});
