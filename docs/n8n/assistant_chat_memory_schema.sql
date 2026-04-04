create table if not exists ai_threads
(
    id
    bigserial
    primary
    key,
    thread_id
    text
    not
    null
    unique,
    user_uuid
    uuid
    not
    null,
    channel
    text
    null,
    summary
    text
    not
    null
    default
    '',
    created_at
    timestamp
    without
    time
    zone
    not
    null
    default
    now
(
),
    updated_at timestamp without time zone not null default now
(
)
    );

create table if not exists ai_messages
(
    id
    bigserial
    primary
    key,
    thread_ref_id
    bigint
    not
    null
    references
    ai_threads
(
    id
) on delete cascade,
    thread_id text not null,
    role text not null check
(
    role
    in
(
    'user',
    'assistant',
    'system'
)),
    content text not null,
    meta jsonb null,
    message_at timestamp
  without time zone null,
    created_at timestamp
  without time zone not null default now
(
),
    updated_at timestamp
  without time zone not null default now
(
)
    );

create index if not exists ai_messages_thread_created_idx
    on ai_messages (thread_id, created_at desc);
