# Generated HestiaCP reverse proxy template: HTTP
# Backend: http://127.0.0.1:{{ $port }}
server {
    listen %ip%:{{ $listen }};
    server_name %domain_idn% %alias_idn%;
    root %docroot%;

    access_log /var/log/%web_system%/domains/%domain%.log combined;
    access_log /var/log/%web_system%/domains/%domain%.bytes bytes;
    error_log /var/log/%web_system%/domains/%domain%.error.log error;

    # Hestia's HTTP-to-HTTPS redirect when Force SSL is enabled.
    include %home%/%user%/conf/web/%domain%/nginx.forcessl.conf*;

    # Deny dotfiles, but allow ACME /.well-known/ paths.
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

    # Important: Hestia inserts temporary ACME-challenge locations here.
    include %home%/%user%/conf/web/%domain%/nginx.conf_*;
}
