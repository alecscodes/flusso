<script setup lang="ts">
import AiController from '@/actions/App/Http/Controllers/Settings/AiController';
import { Button, Checkbox, Input, Label } from '@/components/ui';
import { Form, Head } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

interface AiConfig {
    enabled: boolean;
    driver: string;
    url: string | null;
    model: string | null;
    has_api_key: boolean;
    vision: boolean;
    features: Record<string, boolean>;
}

interface Props {
    config: AiConfig;
    drivers: string[];
    features: string[];
}

const props = defineProps<Props>();

const featureLabels: Record<string, { name: string; hint: string }> = {
    categorize: {
        name: 'Category suggestions',
        hint: 'Suggest a category from the description (your history first, the model only for new text).',
    },
    receipts: {
        name: 'Receipt scanning',
        hint: 'Read a receipt photo into a total or into lines grouped by category.',
    },
    quick_entry: {
        name: 'Quick entry from text',
        hint: 'Type "coffee 2.5 cash" and get the form filled in.',
    },
    statements: {
        name: 'Bank statements',
        hint: 'Parse uploaded statements when the format is not a plain CSV.',
    },
    insights: {
        name: 'Insights',
        hint: 'Plain-language summaries of numbers Flusso computes itself.',
    },
};

const enabled = ref(props.config.enabled);
const vision = ref(props.config.vision);
const driver = ref(props.config.driver);
const url = ref(props.config.url ?? '');
const clearApiKey = ref(false);
const features = reactive<Record<string, boolean>>({
    ...props.config.features,
});

// A saved URL belongs to one provider: never carry it (and the key) over to another one.
watch(driver, () => {
    url.value = '';
});

watch(
    () => props.config,
    (config) => {
        enabled.value = config.enabled;
        url.value = config.url ?? '';
        vision.value = config.vision;
        driver.value = config.driver;
        clearApiKey.value = false;
        Object.assign(features, config.features);
    },
);

const urlHints: Record<string, string> = {
    ollama: 'http://localhost:11434 (or http://ollama:11434 in Docker)',
    'openai-compatible':
        'Required, e.g. http://localhost:1234/v1 (LM Studio, vLLM, a gateway)',
};
</script>

<template>
    <Head title="AI Settings" />

    <div class="space-y-8">
        <div>
            <h2 class="text-xl font-semibold text-foreground">AI</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Use any model you like: a local one through Ollama, or a hosted
                one (OpenAI, Anthropic, Gemini, OpenRouter, a free tier…).
                Everything keeps working without it.
            </p>
        </div>

        <Form
            v-bind="AiController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-6"
            v-slot="{ errors, processing, recentlySuccessful }"
        >
            <input type="hidden" name="enabled" :value="enabled ? '1' : '0'" />
            <input type="hidden" name="vision" :value="vision ? '1' : '0'" />
            <input
                type="hidden"
                name="clear_api_key"
                :value="clearApiKey ? '1' : '0'"
            />

            <div
                class="flex flex-row items-start gap-3 rounded-xl border border-border bg-muted/30 p-4"
            >
                <Checkbox id="enabled" v-model="enabled" class="mt-0.5" />
                <div class="space-y-1">
                    <Label
                        for="enabled"
                        class="cursor-pointer text-sm font-medium"
                    >
                        Enable AI features
                    </Label>
                    <p class="text-sm text-muted-foreground">
                        Only the text you submit to an AI feature is sent to the
                        provider you choose. Balances and account names are not.
                    </p>
                    <p v-if="errors.enabled" class="text-sm text-destructive">
                        {{ errors.enabled }}
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <Label for="driver">Provider</Label>
                <select
                    id="driver"
                    name="driver"
                    v-model="driver"
                    class="flex h-11 w-full rounded-xl border border-input bg-background px-4 py-2 text-base focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none md:text-sm"
                >
                    <option v-for="item in drivers" :key="item" :value="item">
                        {{ item }}
                    </option>
                </select>
                <p v-if="errors.driver" class="text-sm text-destructive">
                    {{ errors.driver }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="model">Model</Label>
                <Input
                    id="model"
                    name="model"
                    :default-value="config.model ?? ''"
                    :error="errors.model"
                    placeholder="e.g. qwen3:4b, gpt-5-mini, claude-haiku-5-5"
                    autocomplete="off"
                />
                <p v-if="errors.model" class="text-sm text-destructive">
                    {{ errors.model }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="url">Base URL (optional)</Label>
                <Input
                    id="url"
                    name="url"
                    type="url"
                    v-model="url"
                    :error="errors.url"
                    :placeholder="
                        urlHints[driver] ??
                        'Leave empty for the provider default'
                    "
                    autocomplete="off"
                />
                <p v-if="errors.url" class="text-sm text-destructive">
                    {{ errors.url }}
                </p>
            </div>

            <div class="space-y-2">
                <Label for="api_key">API key</Label>
                <Input
                    id="api_key"
                    name="api_key"
                    type="password"
                    :error="errors.api_key"
                    :placeholder="
                        config.has_api_key
                            ? 'A key is stored. Type to replace it.'
                            : 'Not needed for local models'
                    "
                    autocomplete="off"
                />
                <div v-if="config.has_api_key" class="flex items-center gap-2">
                    <Checkbox id="clear_api_key" v-model="clearApiKey" />
                    <Label for="clear_api_key" class="cursor-pointer text-sm">
                        Remove the stored key
                    </Label>
                </div>
                <p class="text-xs text-muted-foreground">
                    Stored encrypted, never shown again.
                </p>
                <p v-if="errors.api_key" class="text-sm text-destructive">
                    {{ errors.api_key }}
                </p>
            </div>

            <div
                class="flex flex-row items-start gap-3 rounded-xl border border-border bg-muted/30 p-4"
            >
                <Checkbox id="vision" v-model="vision" class="mt-0.5" />
                <div class="space-y-1">
                    <Label
                        for="vision"
                        class="cursor-pointer text-sm font-medium"
                    >
                        This model can read images
                    </Label>
                    <p class="text-sm text-muted-foreground">
                        Receipt photos go to the model directly. Otherwise they
                        are read with OCR first, which is lighter on a small
                        local model.
                    </p>
                </div>
            </div>

            <div class="space-y-3">
                <h3 class="text-sm font-medium text-foreground">Features</h3>
                <div
                    v-for="feature in props.features"
                    :key="feature"
                    class="flex flex-row items-start gap-3 rounded-xl border border-border p-4"
                >
                    <Checkbox
                        :id="`feature_${feature}`"
                        v-model="features[feature]"
                        class="mt-0.5"
                    />
                    <input
                        type="hidden"
                        :name="`features[${feature}]`"
                        :value="features[feature] ? '1' : '0'"
                    />
                    <div class="space-y-1">
                        <Label
                            :for="`feature_${feature}`"
                            class="cursor-pointer text-sm font-medium"
                        >
                            {{ featureLabels[feature]?.name ?? feature }}
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            {{ featureLabels[feature]?.hint }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-4">
                <p
                    v-show="recentlySuccessful"
                    class="text-sm text-muted-foreground"
                >
                    Saved.
                </p>
                <Button type="submit" :loading="processing">
                    Save Settings
                </Button>
            </div>
        </Form>

        <Form
            v-bind="AiController.test.form()"
            :options="{ preserveScroll: true }"
            v-slot="{ processing }"
        >
            <Button type="submit" variant="outline" :loading="processing">
                Test the saved configuration
            </Button>
        </Form>
    </div>
</template>
