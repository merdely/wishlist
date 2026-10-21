<?php
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.

/*
 * Reverse-proxy (forward-auth) single sign-on.
 *
 * When the app sits behind Caddy + forward_auth (Authelia, Authentik, ...),
 * the proxy adds a header such as "Remote-User: mike" once the user has
 * authenticated.  If that header is present AND the request provably came
 * through the proxy, we log the matching users.username in automatically.
 * Otherwise nothing happens and the normal username/password form is used.
 *
 * The "provably came through the proxy" part matters: anyone can send a
 * Remote-User header themselves.  If the site is ever served without
 * forward_auth in front of it, a forged header would log them in as anyone.
 * So the header is only trusted when at least one of these is configured
 * and satisfied:
 *   - proxy_auth_secret:           the proxy sends a shared secret header
 *   - proxy_auth_trusted_proxies:  REMOTE_ADDR is in a trusted IP/CIDR list
 * If neither is configured, proxy auth stays off.
 */

/* Returns a request header from $_SERVER, e.g. "Remote-User" -> HTTP_REMOTE_USER. */
function proxyAuthHeader($name) {
	if ($name === "" || $name === null) {
		return null;
	}
	$key = "HTTP_" . strtoupper(str_replace("-", "_", $name));
	if (!isset($_SERVER[$key])) {
		return null;
	}
	$value = trim($_SERVER[$key]);
	return $value === "" ? null : $value;
}

/* Is $ip inside $cidr (IPv4 or IPv6; a bare IP is treated as /32 or /128)? */
function proxyAuthIpInCidr($ip, $cidr) {
	$parts = explode("/", trim($cidr), 2);
	$net = @inet_pton($parts[0]);
	$addr = @inet_pton($ip);
	if ($net === false || $addr === false || strlen($net) !== strlen($addr)) {
		return false;
	}
	$maxBits = strlen($net) * 8;
	$bits = isset($parts[1]) ? (int)$parts[1] : $maxBits;
	if ($bits < 0 || $bits > $maxBits) {
		return false;
	}
	$bytes = intdiv($bits, 8);
	if ($bytes > 0 && substr($net, 0, $bytes) !== substr($addr, 0, $bytes)) {
		return false;
	}
	$rem = $bits % 8;
	if ($rem === 0) {
		return true;
	}
	$mask = chr((0xff << (8 - $rem)) & 0xff);
	return (($net[$bytes] & $mask) === ($addr[$bytes] & $mask));
}

/* True if this request came through the trusted proxy. Fails closed. */
function proxyAuthRequestIsTrusted($opt) {
	$secret = isset($opt["proxy_auth_secret"]) ? (string)$opt["proxy_auth_secret"] : "";
	$proxies = isset($opt["proxy_auth_trusted_proxies"]) ? $opt["proxy_auth_trusted_proxies"] : array();
	if (is_string($proxies)) {
		$proxies = array_filter(array_map("trim", explode(",", $proxies)));
	}

	if ($secret === "" && count($proxies) === 0) {
		// Nothing to verify the request against; refuse rather than trust blindly.
		return false;
	}

	if ($secret !== "") {
		$sent = proxyAuthHeader($opt["proxy_auth_secret_header"]);
		if ($sent === null || !hash_equals($secret, $sent)) {
			return false;
		}
	}

	if (count($proxies) > 0) {
		$remote = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";
		$ok = false;
		foreach ($proxies as $cidr) {
			if (proxyAuthIpInCidr($remote, $cidr)) {
				$ok = true;
				break;
			}
		}
		if (!$ok) {
			return false;
		}
	}

	return true;
}

/*
 * Returns the proxy-supplied username (lowercased, like login.php does) if
 * proxy auth is enabled and the request is trusted, otherwise null.
 */
function proxyAuthUsername($opt) {
	if (empty($opt["proxy_auth_enabled"])) {
		return null;
	}
	$user = proxyAuthHeader($opt["proxy_auth_user_header"]);
	if ($user === null) {
		return null;
	}
	if (!proxyAuthRequestIsTrusted($opt)) {
		return null;
	}
	return strtolower($user);
}

/*
 * Called once per request (from MySmarty's constructor), before the page
 * calls session_start() and checks $_SESSION["userid"].
 */
function proxyAuthLogin($smarty) {
	$opt = $smarty->opt();

	// Don't log back in on the request that is logging out.
	$script = basename(isset($_SERVER["SCRIPT_NAME"]) ? $_SERVER["SCRIPT_NAME"] : "");
	if ($script === "setup.php" || ($script === "login.php" && isset($_GET["action"]) && $_GET["action"] === "logout")) {
		return;
	}
	if (headers_sent()) {
		return;
	}

	$username = proxyAuthUsername($opt);
	if ($username === null) {
		return;
	}

	$started_here = false;
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
		$started_here = true;
	}

	// Already logged in as this same proxy user: nothing to do.
	// If the proxy user changed (someone else signed in to Authelia), or the
	// session came from a password login, re-establish it from the header so
	// the app always matches who the proxy says this is.
	if (!(isset($_SESSION["userid"]) && isset($_SESSION["proxy_user"]) && $_SESSION["proxy_user"] === $username)) {
		try {
			$dbh = $smarty->dbh();
			$stmt = $dbh->prepare("SELECT userid, fullname, admin, show_helptext FROM {$opt["table_prefix"]}users WHERE username = ? AND approved = 1");
			$stmt->bindParam(1, $username, PDO::PARAM_STR);
			$stmt->execute();
			$row = $stmt->fetch();
		}
		catch (PDOException $e) {
			error_log("proxy auth: database error: " . $e->getMessage());
			$row = false;
		}

		if ($row) {
			session_regenerate_id(true);
			$_SESSION["userid"] = $row["userid"];
			$_SESSION["fullname"] = $row["fullname"];
			$_SESSION["admin"] = $row["admin"];
			$_SESSION["show_helptext"] = $row["show_helptext"];
			$_SESSION["proxy_user"] = $username;

			// Optionally keep users.fullname in sync with the IdP's display name.
			$name = proxyAuthHeader($opt["proxy_auth_name_header"]);
			if (!empty($opt["proxy_auth_sync_fullname"]) && $name !== null && $name !== $row["fullname"]) {
				try {
					$stmt = $dbh->prepare("UPDATE {$opt["table_prefix"]}users SET fullname = ? WHERE userid = ?");
					$stmt->bindParam(1, $name, PDO::PARAM_STR);
					$stmt->bindParam(2, $row["userid"], PDO::PARAM_INT);
					$stmt->execute();
					$_SESSION["fullname"] = $name;
				}
				catch (PDOException $e) {
					error_log("proxy auth: could not sync fullname: " . $e->getMessage());
				}
			}
		}
		else if (!isset($_SESSION["userid"]) || isset($_SESSION["proxy_user"])) {
			// The proxy vouched for someone who has no (approved) account here.
			// Drop any session that came from a different proxy user and fall
			// back to the login form, which will explain what happened.
			// (A session from a normal password login is left alone.)
			$_SESSION = array();
			$_SESSION["proxy_user_unknown"] = $username;
		}
	}

	if ($started_here) {
		// Release the lock; the page's own session_start() will reopen it.
		session_write_close();
	}
}
?>
