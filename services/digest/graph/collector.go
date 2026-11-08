package graph

import (
	"context"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
)

type Collector interface {
	Collect(context.Context, model.CollectInput) (*model.CollectResult, error)
	PrepareDigest(context.Context, model.PrepareDigestInput) (*model.PreparedDigest, error)
}
