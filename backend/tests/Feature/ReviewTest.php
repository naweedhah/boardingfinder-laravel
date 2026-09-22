<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Review;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    #[Test]
    public function reviews_are_public_and_averaged_to_one_decimal(): void
    {
        $post = Post::factory()->create();
        foreach ([5, 4, 4] as $rating) {
            Review::create(['user_id' => User::factory()->create()->id, 'post_id' => $post->id, 'rating' => $rating, 'comment' => 'ok']);
        }

        $this->getJson("/api/posts/{$post->id}/reviews")
            ->assertOk()
            ->assertJsonPath('count', 3)
            ->assertJsonPath('avgRating', 4.3)
            ->assertJsonCount(3, 'reviews')
            ->assertJsonMissingPath('reviews.0.user.email');

        $empty = Post::factory()->create();
        $this->getJson("/api/posts/{$empty->id}/reviews")->assertJson(['reviews' => [], 'avgRating' => null, 'count' => 0]);
    }

    #[Test]
    public function a_student_reviews_a_listing_once_and_posting_again_edits_it(): void
    {
        $post = Post::factory()->create();
        $student = $this->signIn();

        $this->postJson("/api/posts/{$post->id}/reviews", ['rating' => 3, 'comment' => 'Decent'])
            ->assertOk()->assertJsonPath('message', 'Review saved')->assertJsonPath('review.user.id', $student->id);
        $this->postJson("/api/posts/{$post->id}/reviews", ['rating' => 5, 'comment' => 'Actually great'])
            ->assertOk()->assertJsonPath('review.rating', 5);

        $this->assertSame(1, Review::count());
        $this->assertSame('Actually great', Review::first()->comment);
    }

    #[Test]
    public function ratings_are_validated_and_only_students_may_review(): void
    {
        $post = Post::factory()->create();

        $this->signIn();
        foreach ([0, 6, 'x', null] as $bad) {
            $this->postJson("/api/posts/{$post->id}/reviews", ['rating' => $bad])
                ->assertStatus(422)->assertJson(['message' => 'Rating must be between 1 and 5']);
        }

        $this->signIn($post->owner);
        $this->postJson("/api/posts/{$post->id}/reviews", ['rating' => 5])
            ->assertForbidden()->assertJson(['message' => 'Only students can leave reviews']);

        $this->signIn();
        $this->postJson('/api/posts/nope/reviews', ['rating' => 5])->assertNotFound()->assertJson(['message' => 'Listing not found']);
    }

    #[Test]
    public function you_can_delete_only_your_own_review(): void
    {
        $post = Post::factory()->create();
        $author = $this->signIn();
        $this->postJson("/api/posts/{$post->id}/reviews", ['rating' => 4])->assertOk();

        $this->signIn();
        $this->deleteJson("/api/posts/{$post->id}/reviews")->assertNotFound()->assertJson(['message' => 'Review not found']);

        $this->signIn($author);
        $this->deleteJson("/api/posts/{$post->id}/reviews")->assertOk()->assertJson(['message' => 'Review deleted']);
        $this->assertSame(0, Review::count());
    }
}
