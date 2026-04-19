import SwiftUI
import MapKit

struct NearbyCourtView: View {
    @Environment(LocationManager.self)    private var locationManager
    @Environment(CourtSearchManager.self) private var courtSearch

    @State private var radiusMiles: Double = 25
    @State private var selectedCourt: NearbyCourtResult?
    @State private var cameraPosition: MapCameraPosition = .automatic

    var body: some View {
        VStack(spacing: 0) {
            // Map
            Map(position: $cameraPosition) {
                UserAnnotation()
                ForEach(courtSearch.results) { court in
                    Annotation(court.name, coordinate: court.mapItem.placemark.coordinate) {
                        Button { selectedCourt = court } label: {
                            ZStack {
                                Circle().fill(Color.green).frame(width: 36, height: 36)
                                Image(systemName: "sportscourt.fill")
                                    .font(.system(size: 16))
                                    .foregroundStyle(.white)
                            }
                        }
                    }
                }
            }
            .frame(maxHeight: 260)

            // Controls
            HStack {
                Text("Radius: \(Int(radiusMiles)) mi")
                    .font(.caption).foregroundStyle(.secondary)
                Slider(value: $radiusMiles, in: 5...100, step: 5)
                    .tint(.green)
                Button("Search") {
                    Task {
                        if let loc = locationManager.location {
                            await courtSearch.searchNearby(location: loc, radiusMiles: radiusMiles)
                        } else {
                            locationManager.requestPermission()
                        }
                    }
                }
                .buttonStyle(.borderedProminent)
                .tint(.green)
                .font(.caption)
            }
            .padding(.horizontal).padding(.vertical, 10)
            .background(.regularMaterial)

            Divider()

            // Results
            if courtSearch.isLoading {
                ProgressView("Searching…").padding()
            } else if courtSearch.results.isEmpty {
                ContentUnavailableView(
                    "No Courts Found",
                    systemImage: "sportscourt",
                    description: Text("Try increasing the radius or tap Search.")
                )
            } else {
                List(courtSearch.results) { court in
                    CourtRow(court: court)
                        .onTapGesture {
                            selectedCourt = court
                            cameraPosition = .item(court.mapItem)
                        }
                }
                .listStyle(.plain)
            }
        }
        .navigationTitle("Find Courts")
#if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
#endif
        .onAppear { locationManager.requestPermission() }
        .sheet(item: $selectedCourt) { court in
            CourtDetailSheet(court: court)
        }
    }
}

// MARK: - Court Row

private struct CourtRow: View {
    let court: NearbyCourtResult
    var body: some View {
        HStack {
            Image(systemName: "sportscourt.fill")
                .foregroundStyle(.green)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text(court.name).font(.callout.bold())
                Text(court.address).font(.caption).foregroundStyle(.secondary)
            }
            Spacer()
            Text(court.distanceLabel).font(.caption.monospacedDigit()).foregroundStyle(.secondary)
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Court Detail Sheet

private struct CourtDetailSheet: View {
    let court: NearbyCourtResult
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    Map(position: .constant(.item(court.mapItem))) {
                        Marker(court.name, coordinate: court.mapItem.placemark.coordinate)
                            .tint(.green)
                    }
                    .frame(height: 200)
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .listRowInsets(EdgeInsets())
                }

                Section("Details") {
                    LabeledContent("Distance", value: court.distanceLabel)
                    if !court.address.isEmpty {
                        LabeledContent("Address", value: court.address)
                    }
                    if !court.phone.isEmpty {
                        LabeledContent("Phone", value: court.phone)
                    }
                }

                Section {
                    Button {
                        court.mapItem.openInMaps()
                    } label: {
                        Label("Open in Maps", systemImage: "map.fill")
                    }
                }
            }
            .navigationTitle(court.name)
#if os(iOS)
            .navigationBarTitleDisplayMode(.inline)
#endif
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}
