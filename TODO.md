## Critical Bugs

- [x] **Undefined variable `$return`** - `server/lib/OPodSync/API.php:291`
  - Should be `$r` instead of `$return`
  - Breaks NextCloud login flow

- [x] **Broken CAPTCHA implementation** - `server/lib/OPodSync/GPodder.php:200-220`
  - Uses `sha1($captcha . __DIR__)` which is deterministic
  - Attacker can compute valid CAPTCHA responses
  - Fix: Use server-side session storage for CAPTCHA answer

## Security

- [x] **Weak app-password hashing** - `server/lib/OPodSync/API.php:308-315`
  - Uses SHA1 instead of bcrypt (marked FIXME in code)
  - Fix: Store app-passwords in separate table with proper bcrypt hashing

- [x] **Missing CSRF protection** - `server/templates/register.tpl`, `server/templates/subscriptions.tpl`
  - POST forms have no CSRF tokens

- [x] **Base64 decoded without validation** - `server/_inc.php:92`
  - Authorization header decoded without checking validity

- [x] **Session ID in URL** - `server/lib/OPodSync/GPodder.php:50`
  - External auth passes session ID in query string

## Code Quality

- [ ] **Remove `@` error suppression** - Multiple locations:
  - `server/lib/OPodSync/Feed.php:68,95`
  - `server/lib/OPodSync/GPodder.php:40,116`
  - `server/_inc.php:92`

- [ ] **Add error logging for feed fetches** - `server/lib/OPodSync/Feed.php:57-102`

- [ ] **Add PHP type hints** - `DB.php`, `API.php`, `GPodder.php`

- [ ] **Consistent error handling** - Use exceptions instead of mixed return values

## Testing Gaps

- [ ] CSRF attack prevention
- [ ] CAPTCHA bypass attempts
- [ ] Feed parsing with malformed XML
- [ ] Rate limiting (currently none)

## Features (Would be nice)

- [ ] Implement feed URL normalization (update_urls)
- [ ] Implement device sync API
- [ ] Handle subscriptions per device (on Subscriptions API)

## Future

- [ ] Provide a nice front-end to the web UI
- [ ] Manage and play podcasts from the web UI
