# Reseller API reference

This document describes the LJPc reseller API that the hosting panel talks to.
`Panel\Api\LjpcApiClient` is the live client that implements these endpoints, and
the same API is documented online at https://ljpc-hosting.nl/api-documentation.

## Base URL and authentication

- Base URL: `https://ljpc-hosting.nl` with routes under `/api`.
- All calls authenticate with the header `X-API-Key: <key>`. There is no bearer token
  or cookie involved for a reseller integration.
- The key is an `APIUser`. A permission is only effective when it is granted to BOTH the
  key AND the key's owner user. Granting a permission on only one side denies the call.
  This is why the `/api-access` screen shows the same permission list for the key and its
  owner, and both must be ticked.
- A missing or unknown key returns `HTTP 401` with an envelope body.

### Response envelope

Almost every endpoint wraps its payload in the standard `APIResponse` envelope:

- Success: `{"success": true, "jti": "<uuid>", "data": <payload>}` with the intended HTTP
  status (200, 201, 202).
- Error: `{"success": false, "jti": "<uuid>", "message": "<reason>"}` with a 4xx/5xx status.

Each response also carries an `X-Validation` response header (a short-lived JWT whose `jti`
matches the body). Verifying it is optional; the panel may ignore it.

The client therefore reads `.data` for the payload on success and `.message` for the error
text on failure. Do not look for a `success` flag inside `.data`; the write endpoints put
their human message at `.data.message` on success and at top-level `.message` on error.

### Raw-json exception (products and carts)

The order module's catalog and shopping-cart endpoints are the exception: they are declared
public (no `X-API-Key` required) and return raw JSON, NOT the envelope.

- `GET /api/v1/products` returns `{"products": [ ... ]}` directly (no `success`, no `jti`,
  no `data`, no `X-Validation`).
- `POST/GET /api/v1/carts/*` behave the same (accessed by cart UUID). The panel contract does
  not use carts; only `products` is relevant to `listPackages()`.

## Permissions a reseller key needs (/api-access)

Tick these on BOTH the key and its owner. Group the checkboxes by area below. LJPc maps them to the right access when it issues your key. The area names loosely follow the
group names shown on the `/api-access` screen.

### WHOIS contacts

| Checkbox label | Unlocks |
|---|---|
| View WHOIS contacts through the API | `GET /contacts`, `GET /contacts/{handle}` |
| Create WHOIS contacts through the API | `POST /contacts` |
| Update WHOIS contacts through the API | `PATCH /contacts/{handle}` |

### Domain names

| Checkbox label | Unlocks |
|---|---|
| Check domain availability | `GET /domain-names/check` (used by `checkDomain`) |
| Check domain price | `GET /domain-names/price` (used by `checkDomain`) |

### Hosting

| Checkbox label | Unlocks |
|---|---|
| View domains and sites | `GET /domain-names`, `GET /domain-names/{domain}` |
| Register domains through the API | `POST /domain-names` |
| Transfer domains through the API | `POST /domain-names/{domain}/transfer` |
| Edit own domains | `PATCH /domain-names/{domain}` |
| View auth codes | `GET /domain-names/{domain}/authcode` |
| Show own sites through the API | `GET /sites`, `GET /sites/{id}` |
| Login to sites | `GET /sites/{id}/login` (owner-scoped SSO) |

### DNS

| Checkbox label | Unlocks |
|---|---|
| Manage DNS through the API | `GET`/`PUT /dns/zones/{domain}/records` |

### Order Module

| Checkbox label | Unlocks |
|---|---|
| View own orders | `GET /api/v1/orders`, `GET /api/v1/orders/{id}` |
| Create orders | `POST /api/v1/orders` (hardened restricted path) |

`GET /api/hosting/v1/tlds` and `GET /api/v1/products` need no permission at all (the TLD
catalog needs a valid key; products is fully public).

### Permissions a reseller must NOT be granted

- **Hosting → Login to all sites.** Bypasses site ownership: a key holding it can SSO into
  ANY site, not just the ones it owns or shares. Resellers must be owner-scoped, so leave it
  off. It is granted to nothing by default in production.
- **Order Module → Create orders with custom pricing.** Switches `POST /api/v1/orders` from
  the hardened restricted path to the raw staff path, letting the caller set arbitrary line
  prices, order status, `client_id`, and lifecycle-control meta (for example
  `payment_already_received`). Resellers must stay on the restricted path.
- **Order Module → View all orders** and **Create orders for other clients** likewise widen
  scope beyond a single reseller and must stay off.

## Endpoints

Paths below omit the `https://<host>` prefix. Domain-shaped path parameters accept dots
(`[a-z0-9.-]+`). All examples are real responses captured from the transcript.

### TLD catalog

**`GET /api/hosting/v1/tlds`**. no permission (valid key only).
Returns the full sellable-TLD catalog with list prices (EUR) per year.

```
200 {"success":true,"jti":"...","data":[
  {"tld":"nl","price":3.88,"currency":"EUR","transfer_supported":true},
  {"tld":"2000.hu","price":5,"currency":"EUR","transfer_supported":true}, ... ]}   // 1392 entries
```

`price` is `null` when the price list is unavailable. `transfer_supported` is true
when the TLD supports an auth-code transfer (push-only TLDs are false).

### WHOIS contacts

**`GET /api/hosting/v1/contacts`**. permission `View WHOIS contacts through the API`.
Owner-scoped, paginated (`page`, `per_page` max 100, default 25).

```
200 {"success":true,"jti":"...","data":{"contacts":[ <contact>, ... ],"total":1,"page":1,"per_page":25}}
```

Each `<contact>` is the registrar shape plus `handle`: `{brand, name, addressLine:[...],
postalCode, city, country, email, voice, organization, handle}`.

**`GET /api/hosting/v1/contacts/{handle}`**. permission `View WHOIS contacts through the API`.
Ownership 404 for a foreign handle.

```
200 {"success":true,"jti":"...","data":{"brand":"default","name":"Owned Contact",
  "addressLine":["Teststreet 1"],"postalCode":"1234AB","city":"Testcity","country":"NL",
  "email":"owned@example.com","voice":"+31.612345678","organization":"LJPc",
  "handle":"apitest-owned-0001","customer":"apitest","registries":[],"properties":[],"validations":[]}}
404 {"success":false,"jti":"...","message":"Contact not found"}
```

**`POST /api/hosting/v1/contacts`**. permission `Create WHOIS contacts through the API`.
Body (snake_case): `name` (required), `organization` (optional), `address` (required, single
string), `postal_code`, `city`, `country` (ISO-2, exactly 2 chars), `email`, `voice`
(E.164 or E164a, for example `+31612345678` or `+31.612345678`).

```
201 {"success":true,"jti":"...","data":{"handle":"<new-handle>"}}
422 {"success":false,"jti":"...","message":"The country field must be 2 characters."}
```

**`PATCH /api/hosting/v1/contacts/{handle}`**. permission `Update WHOIS contacts through the API`.
Ownership 404 first, then validation, then registrar update. All body fields optional; unset
fields keep their stored value.

```
200 {"success":true,"jti":"...","data":{"success":true}}
```

### Hosting: domain names

**`GET /api/hosting/v1/domain-names`**. permission `View domains and sites`.
Owner-scoped, paginated (`page`, `per_page` max 100, default 25).

```
200 {"success":true,"jti":"...","data":{"domains":[
  {"domain":"reseller-api-test.nl","status":"active","expires_at":"2027-07-02T16:37:57+02:00",
   "auto_renew":true,"transfer_lock":true}, ... ],"total":2,"page":1,"per_page":25}}
```

**`GET /api/hosting/v1/domain-names/{domain}`**. permission `View domains and sites`.
Merges live registrar info over the stored row (falls back to the row when the registrar is
unavailable). Ownership 404.

```
200 {"success":true,"jti":"...","data":{"domain":"reseller-api-test.nl","status":"active",
  "expires_at":"2027-07-02T16:37:57+02:00","nameservers":["ns1.ljpc.network","ns2.ljpc.network","ns3.ljpc.network"],
  "auto_renew":true,"transfer_lock":true,"contact_handle":"apitest-owned-0001"}}
```

`status` may be a comma-joined registrar status string when live info is present; treat it as
opaque. `contact_handle` is the registrant handle string.

**`POST /api/hosting/v1/domain-names`** (register). permission `Register domains through the API`.
Body: `domain` (required), `contact_handle` (required, must be an owned WHOIS handle), `years`
(optional int 1..10, default 1), `nameservers` (optional, 2..13 hostnames), `external_reference`
(optional). Guard order rejects unsupported TLD / duplicate / unknown or foreign handle before
any registrar write.

```
201 {"success":true,"jti":"...","data":{"domain":"<domain>","status":"active","message":"Domain registered successfully"}}
422 {"success":false,"jti":"...","message":"Domain already exists"}
403 {"success":false,"jti":"...","message":"Contact handle does not belong to you"}
```

**`POST /api/hosting/v1/domain-names/{domain}/transfer`**. permission `Transfer domains through the API`.
Body: `auth_code` (required), `contact_handle` (required, owned), `nameservers` (optional),
`external_reference` (optional).

```
202 {"success":true,"jti":"...","data":{"domain":"<domain>","status":"pending_transfer","message":"Domain transfer initiated successfully"}}
```

**`PATCH /api/hosting/v1/domain-names/{domain}`**. permission `Edit own domains`.
Only-changed semantics. Body: `nameservers` (2..4 hostnames), `auto_renew` (bool),
`transfer_lock` (bool), `whois_contact_handle` (owned handle). Ownership 404. A no-op body
returns 422 `"No changes to apply"`.

```
200 {"success":true,"jti":"...","data":{"domain":"<domain>","message":"Domain updated successfully"}}
```

**`GET /api/hosting/v1/domain-names/{domain}/authcode`**. permission `View auth codes`.
Ownership 404. 404 also when the registrar does not expose a code.

```
200 {"success":true,"jti":"...","data":{"domain":"<domain>","auth_code":"<epp-code>"}}
404 {"success":false,"jti":"...","message":"Auth code is not available for this domain"}
```

### Hosting: pre-existing domain availability and price (used by checkDomain)

**`GET /api/hosting/v1/domain-names/check?domain=<d>`**. permission `Check domain availability`.
Availability is returned as a string keyed by the queried domain.

```
200 {"success":true,"jti":"...","data":{"ljpc-e2e-probe-xyz.nl":"available"}}
```

**`GET /api/hosting/v1/domain-names/price?domain=<d>`**. permission `Check domain price`.
Price is a nested money object keyed by domain, plus a premium flag.

```
200 {"success":true,"jti":"...","data":{"ljpc-e2e-probe-xyz.nl":{"premium":false,
  "price":{"amount":"11.23","currency":"EUR"},"price_eur":{"amount":"11.23","currency":"EUR"}}}}
```

Both accept `domain` as a single value or an array. A domain the price filter does not know
returns `null` for that key.

### Hosting: sites

**`GET /api/hosting/v1/sites`**. permission `Show own sites through the API`.
Owner-scoped (owner plus `extra_user_ids`), paginated.

```
200 {"success":true,"jti":"...","data":{"sites":[
  {"id":2019,"domain":"reseller-api-test.nl","status":"active","disk_space_gb":10}],"total":1,"page":1,"per_page":25}}
```

**`GET /api/hosting/v1/sites/{id}`**. permission `Show own sites through the API`.
Foreign or absent id returns 404 (no enumeration).

```
200 {"success":true,"jti":"...","data":{"id":2019,"domain":"reseller-api-test.nl","status":"active",
  "disk_space_gb":10,"disk_used_bytes":3221225472,"email_used_bytes":1073741824,"disk_quota_bytes":10737418240}}
```

`disk_quota_bytes` is binary GiB (`disk_space_gb * 1024^3`), `null` when unlimited (0 GB).
`disk_used_bytes` / `email_used_bytes` are `null` when no usage row exists yet.

**`GET /api/hosting/v1/sites/{id}/login`** (SSO). permission `Login to sites`.
Owner-scoped unless the caller also holds `Login to all sites` (which resellers must not have).
Returns a single-use Plesk SSO URL, or `204 No Content` when no URL is available.

```
200 {"success":true,"jti":"...","data":{"url":"https://d-....ljpc.systems/enterprise/rsession_init.php?PLESKSESSID=..."}}
204 (empty body)
403 {"success":false,"jti":"...","message":"You don't have permission to login to this site"}
```

### DNS: zone records

Ownership resolves through the caller's `DomainName` (the `{domain}` parameter is split into
sld/tld and must match an owned domain). No matching owned domain returns 404 regardless of
whether a zone exists.

**`GET /api/hosting/v1/dns/zones/{domain}/records`**. permission `Manage DNS through the API`.
404 when the owned domain has no zone yet.

```
200 {"success":true,"jti":"...","data":{"zone":{"domain":"reseller-test-zone.test.com","source":"manual"},
  "records":[{"type":"A","name":"@","content":"192.0.2.10","ttl":3600,"prio":null}, ... ]}}
404 {"success":false,"jti":"...","message":"No DNS zone exists for this domain"}
```

**`PUT /api/hosting/v1/dns/zones/{domain}/records`**. permission `Manage DNS through the API`.
Full replace of the managed record types. Body: `{"records":[ ... ]}` (the array must be
wrapped in a `records` key; an empty array is a valid clear). Creates a `source=manual` zone
on first write. A `source=plesk` zone returns 409.

```
200 {"success":true,"jti":"...","data":{"zone":{"domain":"...","source":"manual"},
  "records":[ ... ],"synced":false}}
422 {"success":false,"jti":"...","message":"records.0: content must be a valid IPv4 address"}
409 {"success":false,"jti":"...","message":"This zone is managed by Plesk and cannot be edited through the API."}
```

Record wire shape `{type, name, content, ttl, prio}`:

- Managed types: `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `SRV`, `CAA`. `NS` is not manageable;
  it is excluded from GET output and preserved (never deleted) on PUT.
- `content` is the rdata as one string. `A/AAAA` = IP, `CNAME` = target, `TXT` = text,
  `MX` = mail host (with `prio`), `SRV` = `"weight port target"` (with `prio`),
  `CAA` = `"flags tag value"`.
- `name` is relative to the zone; `@` is the apex. A fully-qualified name is normalized down.
- `ttl` 60..86400. `prio` required for `MX` and `SRV`, `null` otherwise.
- `synced` reports whether the change reached our DNS; it is often `false` (the sync only fires
  once the domain delegates to our nameservers and meets minimum-record safety checks). A skip
  does not fail the request.

### Order Module: products

**`GET /api/v1/products`**. public, RAW JSON (no envelope, no key).
Optional `?category=<c>` filter.

```
200 {"products":[
  {"uuid":"afe29fe6-...","name":"5GB hosting","billing_period_type":"client_choice",
   "monthly_price":"4.99","yearly_price":"49.90","price":"4.99","category":"hosting",
   "category_identifier":"hosting_5gb","required_fields":[...]},
  {"uuid":"f7b0f46f-...","name":"Domein .media","billing_period_type":"yearly_only",
   "monthly_price":null,"yearly_price":"44.99","price":"44.99","category":"domains",
   "category_identifier":"media","required_fields":null}, ... ]}   // 36 entries
```

Prices are decimal strings. `monthly_price` is `null` for yearly-only products. The list
mixes hosting, domain, and value-pack products; filter by `category`/`category_identifier`.
Fulfillable hosting packages have `category_identifier` matching `hosting_<N>gb`,
`hosting_forward`, or `hosting_dns`.

### Order Module: orders

**`POST /api/v1/orders`**. permission `Create orders` (restricted path).
The restricted path forces `status=pending`, `source=reseller-api`,
`client_id=owner_id=handler_id=caller`, `send_invoice=false`, `send_quote=false`,
`auto_deliver=true`, keeps only `meta.external_reference`, and rebuilds each cart item from a
per-type whitelist with server-side pricing. A posted foreign `client_id` returns 403.

Body:

```
{"summary":"...","meta":{"external_reference":"..."},
 "order_lines":[{"description":"...","cart_item":{ <per-type> }}]}
```

Per-type `cart_item` shapes:

- Hosting: `{"type":"hosting","product_uuid":"<uuid>","domain":"<domain>",
  "billing_period":"monthly|yearly"}` (`billing_period` only required for `client_choice`
  products; `target_url` required for `hosting_forward`).
- Domain: `{"type":"domain","action":"register|transfer","sld":"...","tld":"...",
  "auth_code":"..."}` (`auth_code` required for transfer).
- Own space: `{"type":"hosting_own","space_id":<int>,"domain":"<domain>"}` (price 0).

```
201 {"success":true,"jti":"...","data":{"order":{"id":114,"status":"pending","source":"reseller-api",
  "summary":"...","order_lines":[{"description":"...","price":4.99,"period":1,"cart_item":{...}}],
  "send_invoice":false,"send_quote":false,"auto_deliver":true,"total_price":4.99,
  "needs_subscription":true,"needs_payment":true,"created_at":"...","updated_at":"..."}}}
422 {"success":false,"jti":"...","message":"Order line 1: unsupported cart item type 'hosting_upgrade'"}
403 {"success":false,"jti":"...","message":"You can only create orders for yourself"}
```

The order id lives at `data.order.id`. The order is created pending and dispatched onto the
queue; there is no synchronous provisioning. There are no webhooks, so poll `GET /orders/{id}`.

**`GET /api/v1/orders/{id}`**. permission `View own orders`.
Ownership 403 for an order the caller does not own or handle; 404 when not found.

```
200 {"success":true,"jti":"...","data":{
  "order":{"id":110,"status":"pending","source":"reseller-api","order_lines":[...],
    "summary":"...","total_price":..., "send_invoice":..., ...},
  "simple_status":"pending",
  "provisioning":{"overall":"pending","lines":[
    {"index":0,"type":"hosting","state":"pending","site_id":null,"domain":null},
    {"index":1,"type":"domain","state":"pending","site_id":null,"domain":null}]}}}
```

`simple_status` (and `provisioning.overall`) vocabulary: `pending` (queued / awaiting invoice),
`processing` (delivering), `completed`, `failed`. Per-line `state` vocabulary is `pending`,
`done`, `failed`. When a hosting line completes, its `site_id` is filled in (feed that id to
`GET /sites/{id}`). Raw order meta (error detail, control flags) is never exposed to a
restricted caller.

## ApiClient method to endpoint mapping

`ApiClient` is `Panel\Api\ApiClient`. "Adaptation" is the transformation
`LjpcApiClient` applies on top of unwrapping the `.data` envelope.

| ApiClient method | Endpoint(s) | Adaptation |
|---|---|---|
| `listTlds()` | `GET /api/hosting/v1/tlds` | Return `.data` as-is. `price` may be `null`. |
| `checkDomain($domain)` | `GET /domain-names/check` + `GET /domain-names/price` | Two calls, merged. `available` = (`.data[$domain] === "available"`); `premium` = `.data[$domain].premium`; `price` = `(float) .data[$domain].price_eur.amount`. Unknown TLD: all null/false. |
| `listDomains()` | `GET /domain-names` | Read `.data.domains`; page through until `total` is covered; drop the extra `auto_renew`/`transfer_lock` fields. |
| `getDomain($domain)` | `GET /domain-names/{domain}` | Return `.data`; treat `status` as opaque. |
| `registerDomain($domain,$handle,$opts)` | `POST /domain-names` | Body `{domain, contact_handle:$handle, years:$opts[years], nameservers:$opts[nameservers], external_reference}`. Build `{success: HTTP 2xx, message: .data.message ?? .message}`. |
| `transferDomain($domain,$authCode,$handle,$opts)` | `POST /domain-names/{domain}/transfer` | Domain in path; body `{auth_code, contact_handle:$handle, nameservers, external_reference}`. Success/message as above. |
| `updateDomain($domain,$changes)` | `PATCH /domain-names/{domain}` | Pass `whois_contact_handle` through unchanged. Treat 422 "No changes to apply" as a no-op success if desired. Success/message as above. |
| `getAuthCode($domain)` | `GET /domain-names/{domain}/authcode` | Return `.data.auth_code`. 404 means no code (throw `ApiException`). |
| `getDnsRecords($domain)` | `GET /dns/zones/{domain}/records` | Return `.data.records`. 404 (no zone) should map to an empty array. |
| `saveDnsRecords($domain,$records)` | `PUT /dns/zones/{domain}/records` | Wrap array as `{"records":$records}`. Build `{success: HTTP 2xx, message}`. `synced` is informational. |
| `listContacts()` | `GET /contacts` | Read `.data.contacts`; page through; map each to `{handle, name, email}`. |
| `getContact($handle)` | `GET /contacts/{handle}` | Rename `postalCode`->`postal_code`; `address` = `addressLine[0]`; drop `brand/customer/registries/properties/validations`. |
| `createContact($data)` | `POST /contacts` | Body already snake_case; return `.data.handle`. |
| `updateContact($handle,$data)` | `PATCH /contacts/{handle}` | Build `{success: HTTP 2xx, message}` (`.data` is just `{success:true}`). |
| `listPackages()` | `GET /api/v1/products` | RAW `.products` (no envelope). Filter `category_identifier` to hosting packages; rename `monthly_price`->`wholesale_monthly_price` and cast the decimal string to float (may be null). |
| `createHosting($domain,$categoryIdentifier,$opts)` | `POST /api/v1/orders` | Resolve `$categoryIdentifier`->`product_uuid` via products first. Build a one-line hosting order; pass `meta.external_reference` from `$opts`. Return `{order_id: (string) .data.order.id}`. `$opts[contact_handle]` is not used by hosting lines. |
| `getHosting($siteId)` | `GET /api/hosting/v1/sites/{id}` | Rename `id`->`site_id` (cast to string); keep `disk_used_bytes`/`disk_quota_bytes`; `disk_used_bytes` may be null. |
| `getHostingLoginUrl($siteId)` | `GET /api/hosting/v1/sites/{id}/login` | Return `.data.url`; 204 means no URL (return empty or throw). |
| `getOrderStatus($remoteOrderId)` | `GET /api/v1/orders/{id}` | `status` = `.data.simple_status`; `lines` = `.data.provisioning.lines` mapped to `{type, status: state, site_id}`. Note the line vocabulary is `pending|done|failed` (map `done`->`completed` if the panel expects it). |

## Known caveats

- **Registrar writes are real.** Contact create/update and domain
  register/transfer/update reach the live registrar, so test them carefully. The
  read-only registrar calls (domain info, auth code, availability, price) are safe
  to exercise freely.
- **DNS sync is guarded.** A DNS `PUT` enforces credential, delegation, and
  safety guards before it reaches the DNS backend. For a real reseller zone
  `synced` stays `false` until the domain delegates to our nameservers and meets
  the minimum-record checks. Do not treat `synced:false` as an error.
- **No webhooks.** Provisioning is asynchronous and there is no callback. Poll
  `GET /api/v1/orders/{id}` (`getOrderStatus`) until `completed`/`failed`, then read the site
  via the line `site_id`.
- **Queue is database-driven.** Orders are created `pending` and dispatched onto the DB queue;
  a worker must be running in production for them to provision. In ddev there is no worker.
- **Login is owner-scoped.** `Login to sites` alone only allows SSO into owned/shared sites.
  `Login to all sites` (which resellers must not hold) bypasses that check.
- **Handles, not ids.** `contact_handle` / `whois_contact_id` is the handle string
  everywhere; callers pass a handle, never a numeric id.

## Client adaptation notes

These are the concrete mismatches between each `ApiClient` docblock return shape (the shape
the mock client returns) and the real endpoint responses. `LjpcApiClient` adapts them so the
rest of the panel sees one consistent contract.

1. **Envelope unwrap (all endpoints except products).** The mock returns bare payloads; the
   real endpoints wrap them in `{success, jti, data}`. Read `.data`. On non-2xx, surface
   `.message` as the `ApiException` message.
2. **Write-result shape.** The interface wants `{success, message}` for register / transfer /
   update / saveDnsRecords / updateContact. The real endpoints signal success with the HTTP
   status plus the envelope `success` flag; the message is at `.data.message` (domain writes)
   or absent (DNS/contact). Synthesize `{success, message}` client-side.
3. **`checkDomain` is two calls.** There is no single availability+price endpoint. Call
   `/check` and `/price` and merge. Availability is a STRING (`"available"` etc.) keyed by
   domain, not a bool; price is a nested money object (`price_eur.amount` as a decimal string),
   not a float. Unknown TLD yields nulls.
4. **`listPackages` is raw and renamed.** `GET /api/v1/products` returns raw `{products:[...]}`
   (no envelope). The field is `monthly_price` (decimal string, sometimes null), not
   `wholesale_monthly_price` (float). Cast and rename, and filter out non-hosting products.
5. **`createHosting` takes a product_uuid, not a category.** The endpoint has no
   `category_identifier` input. Resolve category to uuid via `listPackages`/products first,
   then post a one-line hosting order. The order id is `data.order.id` (int), not a top-level
   `order_id` string. `client_choice` products require a `billing_period`. The
   `opts.contact_handle` hint does not apply to hosting order lines.
6. **`getContact` field renames.** Real keys are camelCase and `addressLine` is an ARRAY.
   Map `addressLine[0]`->`address`, `postalCode`->`postal_code`; ignore
   `brand/customer/registries/properties/validations`.
7. **`getHosting` id rename.** Real key is `id`; the interface wants `site_id`. `disk_used_bytes`
   can be `null` (no usage row yet) although the interface types it `int`; the panel should
   tolerate null or the client should coerce to 0.
8. **`getOrderStatus` vocabulary and nesting.** `status` comes from `simple_status`
   (`pending|processing|completed|failed`, already aligned). Lines live under
   `provisioning.lines` with a per-line `state` of `pending|done|failed`; map `state`->`status`
   (`done` is the completed state) and carry `site_id`. The real line also has `index`/`domain`
   the interface does not model.
9. **`getHostingLoginUrl` may be empty.** The interface returns a string; the endpoint returns
   `204 No Content` when there is no URL. Decide on empty-string versus exception.
10. **`getAuthCode` / `getDnsRecords` 404s.** `getAuthCode` 404s when the registrar has no code
    (the interface types a plain string). `getDnsRecords` 404s when the owned domain has no zone
    yet; map that to an empty record list rather than an error.
11. **Pagination.** `listDomains`, `listContacts` (and `listPackages` is unpaginated but large)
    return `{items, total, page, per_page}`. The interface returns a full list, so page through
    until `total` is reached.
12. **Extra permissions for `checkDomain`.** Unlike the write endpoints (the Hosting and WHOIS contact permission
    groups), the availability and price endpoints sit in the `Domain names` permission group
    (`Check domain availability`, `Check domain price`). A reseller key needs those two ticked
    in addition to the Hosting ones, or `checkDomain` returns 403.
