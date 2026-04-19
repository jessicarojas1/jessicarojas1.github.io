import Foundation
import MapKit
import CoreLocation

struct NearbyCourtResult: Identifiable {
    let id = UUID()
    let mapItem: MKMapItem
    let distanceMeters: Double

    var name: String { mapItem.name ?? "Pickleball Court" }
    var distanceMiles: Double { distanceMeters / 1609.34 }
    var distanceLabel: String { String(format: "%.1f mi", distanceMiles) }
    var address: String {
        let p = mapItem.placemark
        return [p.thoroughfare, p.locality, p.administrativeArea]
            .compactMap { $0 }.joined(separator: ", ")
    }
    var phone: String { mapItem.phoneNumber ?? "" }
}

@Observable
final class CourtSearchManager {
    var results:   [NearbyCourtResult] = []
    var isLoading: Bool = false
    var errorMessage: String?

    func searchNearby(location: CLLocation, radiusMiles: Double = 25) async {
        isLoading    = true
        errorMessage = nil
        defer { isLoading = false }

        let radiusMeters = radiusMiles * 1609.34
        let region = MKCoordinateRegion(
            center: location.coordinate,
            latitudinalMeters: radiusMeters * 2,
            longitudinalMeters: radiusMeters * 2
        )

        let request = MKLocalSearch.Request()
        request.naturalLanguageQuery = "pickleball court"
        request.region = region

        do {
            let search   = MKLocalSearch(request: request)
            let response = try await search.start()
            results = response.mapItems
                .map { item in
                    let dist = location.distance(from: CLLocation(
                        latitude:  item.placemark.coordinate.latitude,
                        longitude: item.placemark.coordinate.longitude
                    ))
                    return NearbyCourtResult(mapItem: item, distanceMeters: dist)
                }
                .filter { $0.distanceMeters <= radiusMeters }
                .sorted { $0.distanceMeters < $1.distanceMeters }
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
