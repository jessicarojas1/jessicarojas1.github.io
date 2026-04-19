// swift-tools-version: 5.9
import PackageDescription

let package = Package(
    name: "PickleTrack",
    platforms: [
        .iOS(.v17),
        .macOS(.v14)
    ],
    targets: [
        .executableTarget(
            name: "PickleTrack",
            path: "Sources/PickleTrack",
            swiftSettings: [
                .enableExperimentalFeature("StrictConcurrency")
            ]
        )
    ]
)
