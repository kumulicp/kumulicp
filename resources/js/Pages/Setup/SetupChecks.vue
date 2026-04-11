<script setup>
import BlankLayout from '@/layouts/BlankLayout.vue'
</script>
<template>
  <div class="groups-list">
    <div class="setup-layout row justify-center align-content-center">
      <div class="flex flex-col xs12 lg4 pa-3">
        <va-card class="mb-4">
          <va-card-title>System Check Failed</va-card-title>
          <va-card-content>
            <div class="table-wrapper">
              <table class="va-table va-table--hoverable mt-3">
                <thead>
                  <tr>
                    <th scope="col">
                      Check Name
                    </th>
                    <th scope="col">
                      Result
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(result, name) in checks" :key="name">
                    <td scope="row">
                      {{ name }}
                    </td>
                    <td>
                      <div v-if="result" style="color: green">Passed</div>
                      <div v-else style="color: red">Failed</div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>
<script>
export default {
  layout: (h, page) => h(BlankLayout, [page]),
  props: {
    checks: Object
  }
}
</script>

<style lang="scss">
  .setup-layout  {
    min-height: 100vh;
  }
</style>
