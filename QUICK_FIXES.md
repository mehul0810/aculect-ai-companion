# Quark Plugin - Quick Fixes & Action Items

**Generated:** May 8, 2026  
**Priority:** What to fix first before production release

---

## 🔴 CRITICAL (This Week)

### 1. Add Copy Feedback Timeout (5 min fix)
**File:** `src/index.js`  
**Current Problem:** "Copied" state persists forever  

```javascript
// Line 26-33: Replace copyConfig function
const copyConfig = async () => {
    try {
        await navigator.clipboard.writeText(data.copyAll || '');
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);  // ADD THIS LINE
    } catch (error) {
        setCopied(false);
    }
};

// Line 37-44: Replace copyValue function  
const copyValue = async (value) => {
    try {
        await navigator.clipboard.writeText(String(value || ''));
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);  // ADD THIS LINE
    } catch (error) {
        setCopied(false);
    }
};
```

### 2. Add Confirmation Modal for Revoke (15 min fix)
**File:** `src/index.js`  
**Current Problem:** Users can accidentally revoke without confirmation

```javascript
// Add to SettingsApp function (after line 21):
const [confirmAction, setConfirmAction] = useState(null);

// Update line 230 (revoke button):
{isConnected && (
    <>
        {confirmAction === 'revoke' && (
            <div className="quark-confirmation-modal">
                <div className="modal-overlay">
                    <div className="modal-content">
                        <h4>Revoke ChatGPT Connection?</h4>
                        <p>This will disconnect ChatGPT and revoke all active tokens.</p>
                        <div className="modal-actions">
                            <form method="post" action={data.actions?.adminPostUrl}>
                                <input type="hidden" name="action" value={data.actions?.revokeAction} />
                                <input type="hidden" name="_wpnonce" value={data.actions?.revokeNonce} />
                                <Button isDestructive variant="primary" type="submit">
                                    Yes, Revoke
                                </Button>
                            </form>
                            <Button onClick={() => setConfirmAction(null)}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        )}
        <Button 
            isDestructive 
            onClick={() => setConfirmAction('revoke')}
        >
            Revoke Connection
        </Button>
    </>
)}
```

### 3. Mask Client Secret Display (10 min fix)
**File:** `src/index.js`  
**Current Problem:** Full secret visible in password field

```javascript
// In renderConnectorConfig section (around line 239):
// Replace the client_secret field rendering:

{field.key === 'client_secret' ? (
    <Flex align="flex-end" gap={2} className="quark-config-row">
        <FlexBlock>
            <div className="secret-display">
                <code>
                    {field.value.slice(-4) ? '••••••••••••' + field.value.slice(-4) : '••••••••••••'}
                </code>
            </div>
        </FlexBlock>
        <FlexItem>
            <Button
                className="quark-copy-button"
                label="Copy Client Secret"
                icon="admin-page"
                onClick={() => copyValue(field.value)}
                variant="secondary"
            />
        </FlexItem>
    </Flex>
) : (
    <Flex align="flex-end" gap={2} className="quark-config-row">
        {/* existing code */}
    </Flex>
)}
```

### 4. Add Accessibility Features (20 min fix)
**File:** `src/index.js`  
**Current Problem:** Missing aria-labels and live regions

```javascript
// Add aria-labels to key elements:

// Line 71 (connected notice):
<Notice status="success" isDismissible={false} role="status" aria-live="polite">
    ChatGPT connection marked active.
</Notice>

// Line 76 (revoked notice):
<Notice status="warning" isDismissible={false} role="status" aria-live="polite">
    ChatGPT connection revoked.
</Notice>

// Line 254 (copy button):
<Button
    className="quark-copy-button"
    aria-label={`Copy ${field.label} to clipboard`}
    label={`Copy ${field.label}`}
    icon="admin-page"
    onClick={() => copyValue(field.value)}
    variant="secondary"
/>
```

### 5. Add Error State Handling (30 min fix)
**File:** `src/index.js`  
**Current Problem:** No feedback when errors occur

```javascript
// After line 89 (after the notices):
{data.connectionError && (
    <Notice status="error" isDismissible={false} role="alert" aria-live="assertive">
        <strong>Connection Error:</strong> {data.connectionError}
    </Notice>
)}

{data.tokenExpired && (
    <Notice status="warning" isDismissible={false} role="alert">
        <strong>Token Expired:</strong> Please reconnect your ChatGPT account.
        <br/>
        <Button variant="secondary" href={data.createAppUrl} target="_blank">
            Reconnect Now
        </Button>
    </Notice>
)}
```

---

## 🟡 HIGH PRIORITY (This Month)

### 6. Add Rate Limiting to Token Endpoint
**File:** `src/Connectors/ChatGPT/Rest/OAuthController.php`  
**Severity:** Medium  
**Time:** 45 min

```php
// Add new method to OAuthController class:

private function check_rate_limit(string $client_id): bool
{
    $cache_key = 'quark_oauth_attempts_' . $client_id;
    $attempts = (int) wp_cache_get($cache_key);
    
    if ($attempts > 10) { // 10 attempts per minute
        return false;
    }
    
    wp_cache_set($cache_key, $attempts + 1, '', 60);
    return true;
}

// Add to token() method (line 185), right after getting client_id:
if (!$this->check_rate_limit($client_id)) {
    return new WP_REST_Response([
        'error' => 'too_many_requests',
        'error_description' => 'Too many authentication attempts. Try again later.'
    ], 429);
}
```

### 7. Implement RFC 7009 Revocation Endpoint
**File:** `src/Connectors/ChatGPT/Rest/OAuthController.php`  
**Severity:** Medium  
**Time:** 1 hour

```php
// Add to OAuthController class:

public function token_revocation(WP_REST_Request $request): WP_REST_Response
{
    $token = (string) $request->get_param('token');
    $token_type_hint = (string) $request->get_param('token_type_hint');
    
    if ('' === $token) {
        return new WP_REST_Response([
            'error' => 'invalid_request',
            'error_description' => 'token parameter is required'
        ], 400);
    }
    
    if (!$this->verify_token_endpoint_auth(
        (string) $request->get_param('client_id'),
        (string) $request->get_param('client_secret'),
        (string) $request->get_header('authorization')
    )) {
        return new WP_REST_Response([
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed'
        ], 401);
    }
    
    (new Access())->revoke_token($token);
    
    return new WP_REST_Response([], 200);
}

// Add to register_routes() method:
register_rest_route('quark/v1', '/oauth/revoke', [
    'methods' => WP_REST_Server::CREATABLE,
    'callback' => [$this, 'token_revocation'],
    'permission_callback' => '__return_true',
]);

// Add to oauth_metadata() method:
$metadata['revocation_endpoint'] = rest_url('quark/v1/oauth/revoke');
```

### 8. Add Error Descriptions to OAuth Responses
**File:** `src/Connectors/ChatGPT/Rest/OAuthController.php`  
**Severity:** Low  
**Time:** 30 min

```php
// Replace all error responses:

// Line 199 (invalid_client):
return new WP_REST_Response([
    'error' => 'invalid_client',
    'error_description' => 'The client authentication failed. Verify your client ID and secret.'
], 401);

// Line 209 (invalid_target):
return new WP_REST_Response([
    'error' => 'invalid_target',
    'error_description' => 'The requested resource does not match authorized resources.'
], 400);

// Line 218 (invalid_grant):
return new WP_REST_Response([
    'error' => 'invalid_grant',
    'error_description' => 'The authorization code is invalid, expired, or already used.'
], 400);

// Line 234 (unsupported_grant_type):
return new WP_REST_Response([
    'error' => 'unsupported_grant_type',
    'error_description' => 'The grant type is not supported.'
], 400);
```

### 9. Improve MCP Tool Descriptions
**File:** `src/Connectors/ChatGPT/Rest/McpController.php`  
**Severity:** Low  
**Time:** 30 min

```php
// Line 118-126 (site.list_post_types):
[
    'name' => 'site.list_post_types',
    'title' => 'List Post Types',
    'description' => 'Retrieve all readable post types available on the site. Use this to discover what content types are available before listing items.',
    'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
    'securitySchemes' => $read_security,
],

// Similar improvements for other tools...
```

---

## 🟢 MEDIUM PRIORITY (Month 1-2)

- [ ] Create custom database table for tokens (with encryption)
- [ ] Implement RFC 7662 token introspection endpoint
- [ ] Add `content.update_draft` MCP tool
- [ ] Add comprehensive audit logging
- [ ] Create test suite for OAuth flows
- [ ] Add "Copy All" button functionality enhancement

---

## 📋 Testing Checklist

After implementing fixes, test:

### OAuth Flow
- [ ] Authorization code flow succeeds with PKCE
- [ ] Code expires after 5 minutes
- [ ] Code can only be used once
- [ ] State parameter is validated
- [ ] Invalid client_id is rejected
- [ ] Invalid redirect_uri is rejected

### Settings UI
- [ ] Copy buttons work and show "Copied" for 2 seconds
- [ ] Revoke button shows confirmation modal
- [ ] Client secret shows only last 4 characters
- [ ] Error notices display properly
- [ ] Accessibility labels work with screen readers

### MCP
- [ ] Tools require correct scope
- [ ] Token validation works
- [ ] Expired tokens are rejected
- [ ] Rate limiting works (if implemented)

---

## 📊 Effort Estimation

| Fix | Effort | Impact |
|-----|--------|--------|
| Copy timeout | 5 min | High |
| Confirmation modal | 15 min | High |
| Mask secret | 10 min | High |
| Accessibility | 20 min | Medium |
| Error states | 30 min | High |
| Rate limiting | 45 min | High |
| Revocation endpoint | 1 hour | High |
| Error descriptions | 30 min | Low |
| Tool descriptions | 30 min | Low |

**Total Critical Time:** ~2.5 hours  
**Total with High Priority:** ~4.5 hours

---

## 🚀 Deployment Checklist

Before releasing to production:

- [ ] All critical fixes implemented
- [ ] Settings UI thoroughly tested
- [ ] OAuth flow tested end-to-end
- [ ] Error cases handled gracefully
- [ ] Security review completed
- [ ] Accessibility audit passed
- [ ] Load testing on token operations
- [ ] Browser compatibility tested
- [ ] WCAG 2.1 AA compliance verified
- [ ] Rate limiting verified working

---

## 📞 Questions to Answer

1. **Timeline:** When do you want to release v1.0?
2. **Token Storage:** Are you prepared to migrate from options table to custom DB table?
3. **Testing:** Do you have a staging environment to test OAuth flow with actual ChatGPT?
4. **Scale:** How many concurrent users/tokens are you expecting?
5. **OpenAI Contact:** Should rate limits be tighter than current 10/minute?

