FROM golang:1.25-alpine AS builder

WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download

COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /orchestrateur ./cmd/orchestrateur

FROM alpine:3.20
RUN apk add --no-cache ca-certificates

WORKDIR /app
COPY --from=builder /orchestrateur .

VOLUME ["/data"]

EXPOSE 8000

ENTRYPOINT ["/app/orchestrateur"]
