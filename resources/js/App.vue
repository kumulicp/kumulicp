<template>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet" />
  <router-view />
</template>

<script>
import { useColors, useGlobalConfig } from 'vuestic-ui'
import { computed } from 'vue'

export default {
  setup () {
    const { mergeGlobalConfig, globalConfig } = useGlobalConfig()
    const { setColors } = useColors()

    const setNewLookForOurApplication = () => {
      mergeGlobalConfig({
        colors: { variables: { primary: '#6042B3', secondary: '#d91698' } }
      })
    }

    const primaryColor = computed({
      set (value) {
        setColors({
          primary: value
        })
      }
    })

    primaryColor.value = '#6042B3'

    return { setNewLookForOurApplication, globalConfig }
  }
}
</script>

<style lang="scss">
  @use 'scss/main.scss';

  #app {
    font-family: 'Source Sans Pro', Avenir, Helvetica, Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    color: #2c3e50;
  }

  body {
    margin: 0;
  }
</style>
