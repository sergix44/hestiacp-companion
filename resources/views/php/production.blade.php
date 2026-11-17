; Managed by hcpc php:optimize. Changes here are overwritten on the next run.

; Runtime
memory_limit = {{ $cli ? '-1' : $values['memory_limit'] }}
realpath_cache_size = {{ $values['realpath_cache_size'] }}
realpath_cache_ttl = {{ $values['realpath_cache_ttl'] }}

; Security and logging
display_errors = Off
log_errors = On
expose_php = Off

; OPcache
opcache.enable = 1
@if ($cli)
opcache.enable_cli = 1
@endif
opcache.memory_consumption = {{ $values['opcache.memory_consumption'] }}
opcache.interned_strings_buffer = {{ $values['opcache.interned_strings_buffer'] }}
opcache.max_accelerated_files = {{ $values['opcache.max_accelerated_files'] }}
opcache.validate_timestamps = {{ $values['opcache.validate_timestamps'] }}
opcache.revalidate_freq = {{ $values['opcache.revalidate_freq'] }}
opcache.save_comments = 1

; JIT
opcache.jit = {{ $values['opcache.jit'] }}
opcache.jit_buffer_size = {{ $values['opcache.jit_buffer_size'] }}
