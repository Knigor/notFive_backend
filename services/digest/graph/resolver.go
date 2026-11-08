package graph

// THIS CODE WILL BE UPDATED WITH SCHEMA CHANGES. PREVIOUS IMPLEMENTATION FOR SCHEMA CHANGES WILL BE KEPT IN THE COMMENT SECTION. IMPLEMENTATION FOR UNCHANGED SCHEMA WILL BE KEPT.

import (
	"context"

	"github.com/Knigor/notFive_backend/services/digest/graph/model"
)

type Resolver struct{ Collector Collector }

// Collect is the resolver for the collect field.
func (r *mutationResolver) Collect(ctx context.Context, input model.CollectInput) (*model.CollectResult, error) {
	return r.Collector.Collect(ctx, input)
}

// PrepareDigest is the resolver for the prepareDigest field.
func (r *mutationResolver) PrepareDigest(ctx context.Context, input model.PrepareDigestInput) (*model.PreparedDigest, error) {
	return r.Collector.PrepareDigest(ctx, input)
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
