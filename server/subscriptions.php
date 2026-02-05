<?php

namespace OPodSync;

require_once __DIR__ . '/_inc.php';

if (!$gpodder->user) {
	header('Location: ./login.php');
	exit;
}

$error = null;
$success = null;

if (!empty($_POST) && !$gpodder->checkCSRFToken()) {
	$error = 'Invalid form token, please try again';
}
// Handle new subscription
elseif (!empty($_POST['feed_url'])) {
	$error = $gpodder->addSubscription($_POST['feed_url']);
	if (!$error) {
		$success = 'Successfully subscribed to the feed!';
	}
}
// Handle unsubscribe
elseif (!empty($_POST['unsubscribe']) && is_numeric($_POST['unsubscribe'])) {
	if ($gpodder->removeSubscription((int)$_POST['unsubscribe'])) {
		$success = 'Successfully unsubscribed from the feed.';
	}
}

$subscriptions = $gpodder->listActiveSubscriptions();

$tpl->assign(compact('subscriptions', 'error', 'success'));
$tpl->display('subscriptions.tpl');
