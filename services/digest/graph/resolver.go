package graph

import (
	"context"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
)

type Collector interface {
	Collect(context.Context, model.CollectInput) (*model.CollectResult, error)
}

type Resolver struct{ Collector Collector }

// Collect is the resolver for the collect field.
func (r *mutationResolver) Collect(ctx context.Context, input model.CollectInput) (*model.CollectResult, error) {
	return r.Collector.Collect(ctx, input)
}

// Ping is the resolver for the ping field.
func (r *queryResolver) Ping(ctx context.Context) (int, error) {
	return 1, nil
}

// Mutation returns MutationResolver implementation.
func (r *Resolver) Mutation() MutationResolver { return &mutationResolver{r} }

// Query returns QueryResolver implementation.
func (r *Resolver) Query() QueryResolver { return &queryResolver{r} }

type mutationResolver struct{ *Resolver }
type queryResolver struct{ *Resolver }
