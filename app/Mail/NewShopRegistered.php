<?php

namespace App\Mail;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewShopRegistered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Shop $shop, public User $admin) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New shop registered: ' . $this->shop->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new_shop_registered',
            with: [
                'shop' => $this->shop,
                'admin' => $this->admin,
            ],
        );
    }
}
