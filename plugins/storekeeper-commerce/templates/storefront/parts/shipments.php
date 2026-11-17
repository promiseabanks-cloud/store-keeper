<?php
/**
 * A parcel timeline (§14.3), shared by the order page and the tracking page.
 *
 * Expected: $shipments (list from ShipmentService::forOrder()).
 */

use StoreKeeper\Commerce\Orders\ShipmentStatus;

$shipments = $shipments ?? [];
?>

<?php if ($shipments !== []) : ?>
    <section class="skf-panel skf-timeline" aria-labelledby="skf-timeline-title">
        <h2 id="skf-timeline-title" class="skf-panel__title">Delivery</h2>

        <?php foreach ($shipments as $shipment) : ?>
            <article class="skf-parcel">
                <header class="skf-parcel__head">
                    <strong><?php echo esc_html(ShipmentStatus::label((string) $shipment['status'])); ?></strong>
                    <?php if (!empty($shipment['method_name'])) : ?>
                        <span class="skf-parcel__meta"><?php echo esc_html((string) $shipment['method_name']); ?></span>
                    <?php endif; ?>
                </header>

                <ul class="skf-parcel__lines">
                    <?php foreach (($shipment['items'] ?? []) as $item) : ?>
                        <li>
                            <?php echo esc_html((string) $item['product_name']); ?>
                            <?php if (!empty($item['variant_title'])) : ?>
                                <small><?php echo esc_html((string) $item['variant_title']); ?></small>
                            <?php endif; ?>
                            <span>× <?php echo esc_html((string) $item['qty_pieces']); ?> pieces</span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <dl class="skf-parcel__details">
                    <?php if (!empty($shipment['carrier'])) : ?>
                        <div>
                            <dt>Carrier</dt>
                            <dd><?php echo esc_html((string) $shipment['carrier']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($shipment['waybill_number'])) : ?>
                        <div>
                            <dt>Waybill</dt>
                            <dd><?php echo esc_html((string) $shipment['waybill_number']); ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($shipment['rider_name']) || !empty($shipment['rider_phone'])) : ?>
                        <div>
                            <dt>Rider</dt>
                            <dd>
                                <?php echo esc_html(trim((string) $shipment['rider_name'] . ' ' . (string) $shipment['rider_phone'])); ?>
                                <?php if (!empty($shipment['rider_phone'])) : ?>
                                    <a class="skf-link" href="tel:<?php echo esc_attr((string) $shipment['rider_phone']); ?>">Call</a>
                                <?php endif; ?>
                            </dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <ol class="skf-timeline__steps">
                    <?php foreach (($shipment['events'] ?? []) as $event) : ?>
                        <li class="skf-timeline__step">
                            <span class="skf-timeline__when">
                                <?php echo esc_html(mysql2date(get_option('date_format'), (string) $event['created_at'])); ?>
                            </span>
                            <span class="skf-timeline__what">
                                <?php echo esc_html(ShipmentStatus::label((string) $event['status'])); ?>
                                <?php if (!empty($event['note'])) : ?>
                                    <small><?php echo esc_html((string) $event['note']); ?></small>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
