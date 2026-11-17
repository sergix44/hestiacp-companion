# Generated HestiaCP reverse proxy template: HTTPS
# Backend: http://127.0.0.1:{{ $port }}
server {
    listen %ip%:{{ $listen }} ssl;
    server_name %domain_idn% %alias_idn%;
    root %sdocroot%;

    access_log /var/log/%web_system%/domains/%domain%.log combined;
    access_log /var/log/%web_system%/domains/%domain%.bytes bytes;
    error_log /var/log/%web_system%/domains/%domain%.error.log error;

    ssl_certificate %ssl_pem%;
    ssl_certificate_key %ssl_key%;

    # Hestia's TLS 1.3 early-data anti-replay checks and HSTS.
    if ($anti_replay = 307) { return 307 https://$host$request_uri; }
    if ($anti_replay = 425) { return 425; }
    include %home%/%user%/conf/web/%domain%/nginx.hsts.conf*;

    location ~ /\.(?!well-known/) { return 404; }

    location / {
        proxy_pass http://127.0.0.1:{{ $port }};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_connect_timeout 10s;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
        client_max_body_size 100m;
    }

    include %home%/%user%/conf/web/%domain%/nginx.ssl.conf_*;
}
