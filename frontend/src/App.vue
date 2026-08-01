<template>
  <!--
    Deliberately empty of chrome. The navigation used to live here, which
    meant one bar had to serve both the participant views and the admin
    views - and it showed: "Ziehungen" and "Gebühren" appeared twice, told
    apart only by a trailing gear.
    The chrome now belongs to the layout a route sits under, see router/index.js.
  -->
  <router-view />
</template>
