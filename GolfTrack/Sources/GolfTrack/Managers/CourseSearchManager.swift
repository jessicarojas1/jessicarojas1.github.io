import MapKit
import CoreLocation
import Foundation

// A golf course found via MapKit local search
struct NearbyCourse: Identifiable {
    let id = UUID()
    let mapItem: MKMapItem
    let distanceMeters: Double

    var name: String        { mapItem.name ?? "Unknown Course" }
    var address: String     { formattedAddress() }
    var coordinate: CLLocationCoordinate2D { mapItem.placemark.coordinate }
    var distanceMiles: Double { distanceMeters / 1609.34 }
    var distanceLabel: String {
        distanceMiles < 10
            ? String(format: "%.1f mi", distanceMiles)
            : String(format: "%.0f mi", distanceMiles)
    }
    var phone: String?      { mapItem.phoneNumber }
    var url: URL?           { mapItem.url }

    private func formattedAddress() -> String {
        let p = mapItem.placemark
        let parts = [p.subThoroughfare, p.thoroughfare, p.locality, p.administrativeArea]
            .compactMap { $0 }.filter { !$0.isEmpty }
        return parts.joined(separator: ", ")
    }

    /// Convert to a CustomCourse with a default 18-hole par layout
    func toCustomCourse() -> CourseTemplate {
        let p = mapItem.placemark
        let location = [p.locality, p.administrativeArea].compactMap { $0 }.joined(separator: ", ")
        return CourseTemplate(name: name, location: location)
    }
}

struct CourseTemplate {
    let name: String
    let location: String
    var rating: Double = 72.0
    var slope: Int = 113
    var holes: [HoleInfo] = {
        let pars = [4,4,3,5,4,3,4,5,4, 4,3,4,5,4,3,4,4,5]
        return (1...18).map { n in
            HoleInfo(number: n, par: pars[n-1], yardage: 0, handicapIndex: n)
        }
    }()
    var totalPar: Int { holes.reduce(0) { $0 + $1.par } }
}

@Observable
final class CourseSearchManager {
    var results: [NearbyCourse] = []
    var isSearching = false
    var error: String?
    var searchRegion: MKCoordinateRegion?

    func searchNearby(location: CLLocation, radiusMiles: Double = 25) async {
        await MainActor.run {
            isSearching = true
            error = nil
            results = []
        }

        let radiusMeters = radiusMiles * 1609.34
        let region = MKCoordinateRegion(
            center: location.coordinate,
            latitudinalMeters: radiusMeters * 2,
            longitudinalMeters: radiusMeters * 2
        )

        await MainActor.run { searchRegion = region }

        let request = MKLocalSearch.Request()
        request.naturalLanguageQuery = "golf course"
        request.region = region

        do {
            let response = try await MKLocalSearch(request: request).start()

            let nearby = response.mapItems.compactMap { item -> NearbyCourse? in
                let dest = CLLocation(latitude: item.placemark.coordinate.latitude,
                                      longitude: item.placemark.coordinate.longitude)
                let dist = location.distance(from: dest)
                guard dist <= radiusMeters else { return nil }
                return NearbyCourse(mapItem: item, distanceMeters: dist)
            }
            .sorted { $0.distanceMeters < $1.distanceMeters }

            await MainActor.run {
                results = nearby
                isSearching = false
            }
        } catch {
            await MainActor.run {
                self.error = "Search failed: \(error.localizedDescription)"
                isSearching = false
            }
        }
    }
}
