# syntax=docker/dockerfile:1
FROM --platform=$BUILDPLATFORM golang:1.26-alpine AS build
WORKDIR /src
COPY . .
ARG TARGETOS
ARG TARGETARCH
RUN CGO_ENABLED=0 GOOS=$TARGETOS GOARCH=$TARGETARCH go build -trimpath -ldflags='-s -w' -o /out/server ./cmd/server

FROM alpine:3.22
RUN apk add --no-cache ca-certificates tzdata \
    && addgroup -g 10001 digest \
    && adduser -D -H -u 10001 -G digest digest \
    && mkdir -p /data \
    && chown digest:digest /data
COPY --from=build /out/server /server
USER 10001:10001
ENV LISTEN_ADDR=:8081 DATA_DIR=/data
EXPOSE 8081
HEALTHCHECK --interval=15s --timeout=5s --start-period=5s --retries=3 CMD ["/server", "healthcheck"]
ENTRYPOINT ["/server"]
