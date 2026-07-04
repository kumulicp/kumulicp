<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps({
  screenshots: {
    type: Array,
    default: () => []
  },
  editable: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['remove'])

const strip = ref(null)
const showFullscreen = ref(false)
const activeIndex = ref(0)

const carouselItems = computed(() => props.screenshots.map((screenshot) => ({ src: screenshot.url, alt: '' })))

function scroll (direction) {
  strip.value.scrollBy({ left: direction * 320, behavior: 'smooth' })
}

function open (index) {
  activeIndex.value = index
  showFullscreen.value = true
}

function remove (id) {
  emit('remove', id)
}
</script>

<template>
  <div class="screenshot-gallery" v-if="screenshots.length">
    <va-button
      v-if="screenshots.length > 1"
      class="screenshot-gallery__arrow screenshot-gallery__arrow--left"
      preset="secondary"
      round
      icon="chevron_left"
      :aria-label="$t('components.screenshotGallery.previous')"
      @click="scroll(-1)"
    />
    <div class="screenshot-gallery__strip" ref="strip" data-testid="screenshot-strip">
      <div
        v-for="(screenshot, index) in screenshots"
        :key="screenshot.id"
        class="screenshot-gallery__thumb"
        :data-testid="'screenshot-thumb-'+screenshot.id"
        @click="open(index)"
      >
        <va-image :src="screenshot.url" fit="cover" class="screenshot-gallery__image" />
        <va-button
          v-if="editable"
          class="screenshot-gallery__remove"
          :data-testid="'screenshot-remove-'+screenshot.id"
          preset="secondary"
          round
          size="small"
          icon="close"
          :aria-label="$t('common.remove')"
          @click.stop="remove(screenshot.id)"
        />
      </div>
    </div>
    <va-button
      v-if="screenshots.length > 1"
      class="screenshot-gallery__arrow screenshot-gallery__arrow--right"
      preset="secondary"
      round
      icon="chevron_right"
      :aria-label="$t('components.screenshotGallery.next')"
      @click="scroll(1)"
    />

    <va-modal
      v-model="showFullscreen"
      fullscreen
      hide-default-actions
      no-padding
      class="screenshot-gallery__modal"
    >
      <template #default>
        <va-button
          class="screenshot-gallery__modal-close"
          preset="secondary"
          round
          icon="close"
          :aria-label="$t('common.cancel')"
          @click="showFullscreen = false"
        />
        <va-carousel
          v-if="showFullscreen"
          v-model="activeIndex"
          :items="carouselItems"
          arrows
          :indicators="false"
          :autoscroll="false"
          height="100vh"
        />
      </template>
    </va-modal>
  </div>
</template>

<style lang="scss">
.screenshot-gallery {
  position: relative;
  display: flex;
  align-items: center;

  &__strip {
    display: flex;
    gap: 0.75rem;
    overflow-x: auto;
    scroll-snap-type: x proximity;
    padding: 0.25rem;
    width: 100%;
  }

  &__thumb {
    position: relative;
    flex: 0 0 auto;
    width: 220px;
    height: 140px;
    scroll-snap-align: start;
    cursor: pointer;
    border-radius: 6px;
    overflow: hidden;
  }

  &__image {
    width: 100%;
    height: 100%;
  }

  &__remove {
    position: absolute;
    top: 4px;
    right: 4px;
  }

  &__arrow {
    flex: 0 0 auto;
    z-index: 1;
  }

  &__modal .va-modal__dialog {
    background: rgba(0, 0, 0, 0.9);
  }

  &__modal-close {
    position: absolute;
    top: 1rem;
    right: 1rem;
    z-index: 2;
  }
}
</style>
