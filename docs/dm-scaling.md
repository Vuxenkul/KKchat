# DM scalability plan

This project currently stores room and DM traffic in the same `messages` table, with DMs identified by `recipient_id` and filtered by `hidden_at IS NULL`.

## Current bottlenecks seen in code

1. **Two-sided DM thread fetches use an `OR` predicate** (`A->B OR B->A`), which typically forces MySQL to do index-merge work instead of a single clean range scan.
2. **Unread-by-peer checks aggregate from raw messages** (`recipient_id = ?`, `GROUP BY sender_id`) and then compare against `last_dm_reads`.
3. **Inbox-like concerns are still read from base message rows**, so high row counts can directly affect hot-path APIs.

## Recommended approach (highest impact first)

## 1) Add a canonical thread key to `messages` (keep one table)

Add two nullable/generated columns for DMs:

- `dm_user_low`
- `dm_user_high`

For DM rows:

- `dm_user_low = LEAST(sender_id, recipient_id)`
- `dm_user_high = GREATEST(sender_id, recipient_id)`

For room rows (`recipient_id IS NULL`), keep these as `NULL`.

Then add:

- `INDEX idx_dm_thread (dm_user_low, dm_user_high, hidden_at, id)`

Why this is stronger than the current pair of directional indexes:

- Removes the `OR` thread predicate and replaces it with one equality+range pattern.
- Makes pagination (`id > ?` / last N by `id DESC`) predictable for the optimizer.

Query shape becomes:

```sql
SELECT ...
FROM messages
WHERE dm_user_low = ?
  AND dm_user_high = ?
  AND hidden_at IS NULL
  AND id > ?
ORDER BY id ASC
LIMIT ?;
```

## 2) Add a `dm_threads` summary table for inbox and badges

Create a lightweight per-thread table (one row per pair):

- key: `(user_low, user_high)`
- `last_msg_id`, `last_msg_at`, `last_sender_id`
- `last_preview` (optional)
- counters (optional): `msg_count`, `active_until`

Use it for:

- inbox list ordering
- latest message preview
- fast existence/activity checks

Keep `messages` for full thread history only.

Update strategy:

- synchronous on insert for correctness, or
- append-only job queue + async projector for smoother writes at high scale.

## 3) Improve unread computation path

Current unread check works, but at scale use one of these:

- **Option A (minimal schema change):** add `INDEX (recipient_id, hidden_at, sender_id, id)` to better support grouped scans.
- **Option B (best long-term):** maintain per-user-per-thread state table, e.g. `dm_thread_user_state(user_id, peer_id, last_read_id, unread_count, updated_at)`.

Option B removes repeated grouping over `messages` in polling endpoints.

## 4) Use cursor pagination everywhere for DM history

Prefer seek pagination by `id` over offsets:

- older: `id < :before_id ORDER BY id DESC LIMIT :n`
- newer: `id > :after_id ORDER BY id ASC LIMIT :n`

This keeps large threads cheap.

## 5) Optional: physically separate DM and room storage later

If growth is extreme, split into `dm_messages` and `room_messages` (or partitioning), but only after thread-key + summary table. Most systems get enough headroom from steps 1–3.

## Suggested rollout sequence

1. Add thread key columns + index.
2. Switch DM fetch endpoints to thread-key predicates.
3. Add `dm_threads` and backfill once.
4. Move inbox/unread hot paths to summary/state tables.
5. Keep old paths behind a feature flag for rollback.

## Why this is likely the best next step for this codebase

- It preserves your existing one-table model and read watermark concept.
- It directly targets the two currently expensive query shapes.
- It gives a low-risk migration path (no immediate full data split required).
