<template lang="pug">
	div
		transition(name="fade")
			.uk-card.uk-card-default(v-if="!failed && applications")
				.uk-card-header
					h2.uk-h3 {{ t('Updates') }}

				.uk-card-body
					table.uk-table.uk-table-hover.uk-table-divider.uk-table-middle
						thead
							tr
								th {{ t('App') }}
								th {{ t('Developer') }}
								th {{ t('Update Info') }}
								th &nbsp;
						tbody
							tr(v-for="application in applications")
								td
									router-link(:to="{ name: 'details', params: { id: application.id }}").uk-h5 {{ application.name }}
								td
									a(:href="application.publisher.url", target="_blank") {{ application.publisher.name }}
								td
									span {{ application.installInfo.version }}
									span(uk-icon="icon: arrow-right").uk-margin-small-left.uk-margin-small-right
									select(v-model="application.updateTo" v-if="application.minorUpdate !== false && application.majorUpdate !== false")
										option(:value="application.minorUpdate.version" selected="selected") {{ application.minorUpdate.version }}
										option(:value="application.majorUpdate.version") {{ application.majorUpdate.version }}
									span(v-else-if="application.minorUpdate !== false") {{ application.minorUpdate.version }}
										input(type="hidden" v-model="application.updateTo" value="application.minorUpdate.version")
									span(v-else-if="application.majorUpdate !== false") {{ application.majorUpdate.version }}
										input(type="hidden" v-model="application.updateTo" value="application.majorUpdate.version")
								td
									button.uk-button.uk-button-primary.uk-align-right.uk-margin-remove.uk-position-relative(@click="update(application.id, application.updateTo)", :disabled="processing(application.id)")
										span(v-if="processing(application.id)")
											span.uk-position-small.uk-position-center-left(uk-spinner, uk-icon="icon: spinner; ratio: 0.8")
											span.uk-margin-small-left &nbsp;&nbsp; {{ t('updating') }}
										span(v-else)
											| {{ t('update') }}
							tr(v-if="applications.length === 0 && !loading")
								td(colspan="4").uk-text-center
									span.uk-text-primary {{ t('All apps are up to date') }}
							tr(v-show="loading")
								td(colspan="4").uk-text-center
									span(uk-spinner, uk-icon="icon: spinner")

</template>

<script>
	import Tile from './Tile.vue';

	export default {
		components: {
			Tile
		},
		methods: {
			update (id, version) {
				this.$store.dispatch('PROCESS_APPLICATION', [id, 'update', {'version' : version}])
			},
			processing(id) {
				return _.contains(this.$store.state.processing, id)
			},
			t(string) {
				return this.$gettext(string);
			}
		},
		computed : {
			loading() {
				return this.$store.state.applications.loading
			},
			failed() {
				return this.$store.state.applications.failed
			},
			applications() {
				if (this.loading || this.failed) {
					return []
				} else {
					return this.$store.getters.updateList
				}
			}
		}
	}
</script>

<style lang="scss" scoped>
	@import "../styles/variables-theme";
</style>
