<script setup lang="ts">
import { CategoryBadge } from '@/components/data-display';
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui';
import { index as categoriesIndex } from '@/routes/categories';
import type { Category } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Tag } from 'lucide-vue-next';

interface Props {
    category: Category;
}

defineProps<Props>();
</script>

<template>
    <Head :title="category.name" />

    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <Button variant="ghost" size="icon" as-child>
                <Link :href="categoriesIndex().url">
                    <ArrowLeft class="size-4" />
                </Link>
            </Button>
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ category.name }}
                </h1>
                <p class="text-sm text-muted-foreground">Category details</p>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Tag class="size-5" />
                    Category
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <CategoryBadge :category="category" />
                <p class="text-sm text-muted-foreground capitalize">
                    Type: {{ category.type }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
