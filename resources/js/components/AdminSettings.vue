<template>
    <div class="row my-2">
      <div class="flex flex-col xs12 lg6">
        <div>
          <h4 class="va-h4"><slot name="name">Settings</slot></h4>
          <div class="va-list-item-label--caption"><slot name="description"></slot></div>
        </div>
      </div>
      <div class="flex flex-col xs12 lg6">
        <slot name="settings"></slot>
      </div>
    </div>
</template>
