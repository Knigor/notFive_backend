package news

import (
	"context"
	"errors"
	"fmt"
)

type SourceError struct {
	Cause  error
	Code   string
	Detail string
}

func (e *SourceError) Error() string {
	if e.Detail != "" {
		return fmt.Sprintf("%s: %s", e.Code, e.Detail)
	}
	return e.Code
}

func (e *SourceError) Unwrap() error { return e.Cause }

func failure(cause error, code, detail string) error {
	return &SourceError{Cause: cause, Code: code, Detail: detail}
}

func ErrorCode(err error) string {
	if detail, ok := errors.AsType[*SourceError](err); ok {
		return detail.Code
	}
	switch {
	case errors.Is(err, ErrListingPage):
		return "listing_page"
	case errors.Is(err, ErrUnknownDate):
		return "publication_date_unconfirmed"
	case errors.Is(err, ErrUntrustedURL):
		return "untrusted_url"
	case errors.Is(err, context.DeadlineExceeded):
		return "request_timeout"
	case errors.Is(err, context.Canceled):
		return "request_cancelled"
	default:
		return "source_unavailable"
	}
}
