<template>
  <div class="row row-equal">
    <div class="flex x16 xs12 lg12">
      <div class="row">
        <div v-for="(info, idx) in blocks" :key="idx" class="flex xs12 sm3">
          <va-card class="mb-4">
            <va-card-content>
              <h2 class="va-h2 ma-0">{{ info.value }}</h2>
              <p>{{ info.label }}</p>
            </va-card-content>
          </va-card>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  data () {

  },
  props: {
    blocks: Object
  }
}
</script>

<style></style>
