<?php

declare(strict_types=1);

namespace Panel;

final class Cart
{
    public function add(array $item): void
    {
        $items = $this->items();
        foreach ($items as $i => $existing) {
            if ($existing['type'] === $item['type'] && $existing['domain'] === $item['domain']) {
                $items[$i] = $item;
                $_SESSION['cart'] = array_values($items);

                return;
            }
        }
        $items[] = $item;
        $_SESSION['cart'] = array_values($items);
    }

    public function remove(int $index): void
    {
        $items = $this->items();
        unset($items[$index]);
        $_SESSION['cart'] = array_values($items);
    }

    public function items(): array
    {
        return $_SESSION['cart'] ?? [];
    }

    public function totalCents(): int
    {
        return array_sum(array_column($this->items(), 'price_cents'));
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function clear(): void
    {
        $_SESSION['cart'] = [];
    }
}
