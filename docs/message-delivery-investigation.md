# Message delivery investigation (messages inserted but not received)

## What this review covered
- Server send path: `/message` insert and DM/room addressing.
- Server read path: `/sync` (primary) and `/fetch` (history/prefetch).
- Client poll loop, cursor handling, and cross-tab behavior.

## High-probability bottlenecks

### 1) Global sync concurrency is extremely strict (default = 1)
`/sync` uses a transient lock with a global in-flight slot count. The default setting is `1`, so concurrent sync calls are denied with HTTP `429 sync_busy`.

- Concurrency guard and deny path live in sync endpoint and helper functions.
- Under real traffic (many users polling every few seconds), this can starve clients, making delivery appear "missing" until a later successful poll.

**Code pointers**
- `kkchat_sync_acquire_slot()` / limit default from option `kkchat_sync_concurrency` with fallback `1`.
- `/sync` returns `429` when a slot is unavailable.

### 2) Rate guard can enforce temporary bans after rapid polls
A per-user rate guard marks a short penalty if requests arrive < 0.9s apart, returning `429 rate_limited` (default penalty ~6s).

In unstable networks or multi-tab edge cases, this can repeatedly delay polling and make users think they did not receive new messages.

### 3) Client does not fast-drain backlog from `/sync` because of payload shape mismatch
Client `performPoll()` tries to detect "full page" responses and immediately re-poll when `msgCount >= 50`.

But the server returns messages inside `events[0].messages` (not top-level `messages`). The client computes:

- `msgCount = Array.isArray(payload?.messages) ? payload.messages.length : 0`

So `msgCount` is usually `0`, and fast-drain does not trigger. If a user fell behind, they get only one page per poll interval, creating long perceived delays.

### 4) Filtering can intentionally hide rows that still exist in DB
Several server filters mean "row exists" != "recipient sees it":
- Hidden messages (`hidden_at IS NULL` required in fetch/sync queries).
- Blocked-sender filtering on both room and DM fetch paths.
- Room access restrictions for members-only rooms in unread computations.

This can look like delivery failure unless logs distinguish "stored" from "eligible for recipient view".

## Cursor-specific assessment
A pure cursor bug is **possible but less likely** than throttling/backlog issues.

Why:
- Server cursor for `/sync` is computed from delivered message IDs.
- Client intentionally avoids advancing cursor on local send finalize to prevent skipping unseen messages.
- Context (room/DM) has separate ETags and state keys.

The main cursor-adjacent issue is backlog draining speed (item #3), not permanent cursor corruption.

## Suggested immediate checks in production
1. Check admin sync metrics for spikes in:
   - `concurrency_denied`
   - `rate_limited`
   - `breaker_denied`
2. Temporarily raise `kkchat_sync_concurrency` above `1` (e.g. 5-20 depending on host capacity).
3. Verify client logs for repeated 429/503 around affected users.
4. Add a debug response header showing whether rows were filtered as hidden/blocked for that request context.

## Recommended fixes (priority order)
1. **Fix fast-drain logic** in client to count messages from `events` as well as top-level fallback.
2. Increase default sync concurrency from `1` to a safer baseline.
3. Soften or make adaptive the 0.9s rate guard threshold under leader-election/multi-tab churn.
4. Add structured observability per sync response:
   - requested context (room/dm)
   - since cursor
   - rows fetched
   - rows dropped by hidden/block filters
   - returned cursor

